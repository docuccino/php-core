<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use JsonException;
use stdClass;
use Throwable;

/**
 * The OAS half of contract checking: match an {@see Exchange} to its operation, pick the documented
 * response for the status, the `content` entry and the `headers` map for the response and the
 * parameters and body for the request, then hand each payload to {@see SchemaCheck}. {@see delivery()}
 * is the outbound half, and reaches the same body check by the same road.
 *
 * Where the contract cannot be checked rather than being wrong — a `text/csv` body, a media type or a
 * header with no schema — the outcome passes with a NOTE rather than passing silently. A pass that
 * proved nothing and says nothing is how a suite ends up believing it has contract coverage it does not
 * have, so a note is owed a reader: {@see ContractMessages::uncheckedExchange()} is how one is said.
 * A document that points NOWHERE is the other case and is not a note: a `$ref` at a name nothing
 * defines is broken rather than uncheckable, so it fails naming the pointer — otherwise one typo in a
 * reference turns the contract it guards into a no-op that reports success.
 */
final class ContractChecker
{
    private readonly SchemaCheck $schema;

    public function __construct(private readonly ContractIndex $index)
    {
        $this->schema = new SchemaCheck($index);
    }

    public function check(Exchange $exchange, bool $checkRequest = true, bool $checkResponse = true): CheckResult
    {
        $operation = $this->index->match($exchange->method, $exchange->path);

        if ($operation === null) {
            return new CheckResult(null);
        }

        return new CheckResult(
            operation: $operation,
            request: $checkRequest ? $this->request($operation, $exchange) : null,
            response: $checkResponse ? $this->response($operation, $exchange) : null,
        );
    }

    /**
     * The payload the application dispatched for a documented webhook, against the body the document
     * publishes for it.
     *
     * The outbound half. There is no exchange to match — a webhook is found by name — so the caller
     * resolves it off {@see ContractIndex::webhooksNamed()} and hands the one it means here.
     *
     * A webhook the document publishes NO body for fails rather than noting, which is the one place the
     * outbound half parts company with the inbound one's notes. Everything else here is the check saying
     * it cannot read what the document published; that is the document publishing nothing at all, which
     * is what an undocumented status already is on the way in — there is no contract to be right or
     * wrong about, and a pass would claim one had been checked.
     */
    public function delivery(ContractWebhook $webhook, string $payload, bool $ambiguousEmptyPayload = false): Outcome
    {
        $documented = $webhook->requestBody($this->index->document());

        if ($documented === null) {
            return Outcome::failed([Violation::ofExchange(
                'documents no delivered body, so there is nothing here for a payload to be held to',
                $webhook->label(),
            )]);
        }

        [$body, $segments, $dangling] = $documented;

        if ($dangling !== null) {
            return Outcome::failed([Violation::unresolvedRef($dangling, 'the delivered body')]);
        }

        $content = $body['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return Outcome::passed(sprintf('the contract documents a delivered body with no media types for %s', $webhook->label()));
        }

        /** @var array<string, mixed> $content */
        $key = MediaType::select($content, null);

        if ($key === null) {
            return Outcome::passed(sprintf(
                'the contract documents %s under several media types (%s), so there is nothing here one payload answers to',
                $webhook->label(),
                implode(', ', array_map(strval(...), array_keys($content))),
            ));
        }

        return $this->body(
            $payload,
            $content[$key],
            [...$segments, 'content', $key, 'schema'],
            $key,
            'the delivered payload',
            'the delivery',
            $ambiguousEmptyPayload,
        );
    }

    /**
     * One half of {@see check()}, which is the entry point — these two are separate only so a test can
     * reach a pairing check() cannot produce.
     *
     * @internal
     */
    public function response(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $document = $this->index->document();
        $documented = $operation->responseFor($document, $exchange->status);

        if ($documented === null) {
            return Outcome::failed([Violation::ofExchange(sprintf(
                'responded %d, which the contract does not document (it documents %s)',
                $exchange->status,
                $operation->documentedStatuses(),
            ))]);
        }

        [$response, $segments, $dangling] = $documented;

        if ($dangling !== null) {
            return Outcome::failed([Violation::unresolvedRef($dangling)]);
        }

        $headers = $this->responseHeaders($response, $segments, $exchange);
        $body = $this->responseBody($response, $segments, $exchange);

        $violations = [...$headers->violations, ...$body->violations];

        return $violations === []
            ? Outcome::passed(self::note($headers->note, $body->note))
            : Outcome::failed($violations);
    }

    /**
     * The documented `headers` map against what came back.
     *
     * A header the response sent MORE THAN ONCE is checked once per value: the contract says what the
     * header looks like, and a response that sent `Set-Cookie` three times made that claim three times.
     * Joining them into one comma list would hand the schema a value nothing sent, and RFC 9110 forbids
     * joining `Set-Cookie` at all.
     *
     * @param  array<string, mixed>  $response
     * @param  list<string>  $segments
     */
    private function responseHeaders(array $response, array $segments, Exchange $exchange): Outcome
    {
        $violations = [];
        $notes = [];

        foreach (ResponseHeaders::of($this->index->document(), $response, $segments) as $header) {
            [$found, $note] = $this->checkParameter(
                $header,
                $exchange->responseHeader($header->name),
                // An absent OPTIONAL header is not a violation — the contract said it might be there.
                $header->required ? 'is documented as required, but the response did not send it' : null,
            );

            foreach ($found as $violation) {
                $violations[] = $violation;
            }

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $violations === [] ? Outcome::passed(self::note(...$notes)) : Outcome::failed($violations);
    }

    /**
     * One documented parameter against the values the exchange carried under its name: unresolvable
     * declaration, then absent-but-required, then a schema nothing can be checked against, then the
     * values themselves. Both halves ask this — OAS makes a response header object a parameter without
     * `name` and `in` ({@see ContractParameter}), so the two would otherwise each carry a copy of the
     * order those come in, and drift.
     *
     * @param  list<mixed>  $values  every value sent under that name; a request parameter has at most
     *                               one, a response may have sent a header several times
     * @param  string|null  $absent  what to say when nothing was sent, or null where absence is not a
     *                               violation
     * @return array{0: list<Violation>, 1: string|null}
     */
    private function checkParameter(ContractParameter $parameter, array $values, ?string $absent): array
    {
        // A `$ref` at a name the document does not define leaves nothing to read `required` or `schema`
        // off — so a required header behind one would check as optional-and-unschema'd and report a
        // pass. A document that points nowhere is broken, not uncheckable, so it fails naming the
        // pointer.
        if ($parameter->danglingRef !== null) {
            return [[Violation::unresolvedRef(
                $parameter->danglingRef,
                $parameter->label(),
                Pointer::of($parameter->segments),
            )], null];
        }

        if ($values === []) {
            if ($absent === null) {
                return [[], null];
            }

            // The pointer names the DECLARATION, which is the node that says `required`; the trail is
            // read from its schema, which is the node a producer signs.
            return [[new Violation(
                location: $parameter->label(),
                pointer: '',
                message: $absent,
                schemaPointer: Pointer::of($parameter->segments),
                provenance: ProvenanceTrail::at($this->index->document(), $parameter->schemaSegments()),
            )], null];
        }

        if (! $parameter->hasSchema()) {
            return [[], isset($parameter->definition['content'])
                ? sprintf('%s is documented as a content object, which the check does not decode', $parameter->label())
                : sprintf('the contract documents no schema for %s', $parameter->label())];
        }

        $violations = [];
        foreach ($values as $index => $value) {
            $label = count($values) === 1 ? $parameter->label() : sprintf('%s (value %d)', $parameter->label(), $index + 1);

            foreach ($this->validate(
                ParameterValue::coerce($value, $parameter->schema(), $this->index->document()),
                $parameter->schemaSegments(),
                $label,
            ) as $violation) {
                $violations[] = $violation;
            }
        }

        return [$violations, null];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $segments
     */
    private function responseBody(array $response, array $segments, Exchange $exchange): Outcome
    {
        $content = $response['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return trim($exchange->responseBody) === ''
                ? Outcome::passed()
                : Outcome::failed([Violation::ofExchange(sprintf(
                    'documents no body for %d, but the response returned %d bytes',
                    $exchange->status,
                    strlen($exchange->responseBody),
                ))]);
        }

        /** @var array<string, mixed> $content */
        $requested = MediaType::base($exchange->responseContentType);
        $key = MediaType::select($content, $requested);

        if ($key === null) {
            return Outcome::failed([Violation::ofExchange(sprintf(
                'returned %s, which the contract does not document for %d (it documents %s)',
                $requested ?? 'no content type',
                $exchange->status,
                implode(', ', array_map(strval(...), array_keys($content))),
            ))]);
        }

        return $this->body(
            $exchange->responseBody,
            $content[$key],
            [...$segments, 'content', $key, 'schema'],
            $key,
            'the response body',
            'the response',
        );
    }

    /**
     * The other half. {@see response()}.
     *
     * @internal
     */
    public function request(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $parameters = $this->parameters($operation, $exchange);
        $body = $this->requestBody($operation, $exchange);

        $violations = [...$parameters->violations, ...$body->violations];

        return $violations === []
            ? Outcome::passed(self::note($parameters->note, $body->note))
            : Outcome::failed($violations);
    }

    private function parameters(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $bound = $operation->bind($exchange->path) ?? [];

        $violations = [];
        $notes = [];

        foreach ($operation->parameters as $parameter) {
            $value = match ($parameter->in) {
                'path' => $bound[$parameter->name] ?? null,
                'query' => $exchange->query[$parameter->name] ?? null,
                'header' => $exchange->header($parameter->name),
                'cookie' => $exchange->cookies[$parameter->name] ?? null,
                default => null,
            };

            [$found, $note] = $this->checkParameter(
                $parameter,
                $value === null ? [] : [$value],
                // A missing PATH parameter is not a contract violation: the request could not have
                // matched this template without one, so its absence means the template did not bind.
                $parameter->required && $parameter->in !== 'path'
                    ? 'is documented as required, but the request did not send it'
                    : null,
            );

            foreach ($found as $violation) {
                $violations[] = $violation;
            }

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $violations === [] ? Outcome::passed(self::note(...$notes)) : Outcome::failed($violations);
    }

    private function requestBody(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $documented = $operation->requestBody($this->index->document());

        if ($documented === null) {
            return Outcome::passed();
        }

        [$body, $segments, $dangling] = $documented;

        if ($dangling !== null) {
            return Outcome::failed([Violation::unresolvedRef($dangling, 'the request body')]);
        }

        if (trim($exchange->requestBody) === '') {
            return ($body['required'] ?? false) === true
                ? Outcome::failed([Violation::ofExchange('sent no request body, which the contract documents as required', 'the request')])
                : Outcome::passed();
        }

        $content = $body['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return Outcome::passed('the contract documents a request body with no media types');
        }

        /** @var array<string, mixed> $content */
        $requested = MediaType::base($exchange->requestContentType);
        $key = MediaType::select($content, $requested);

        if ($key === null) {
            return Outcome::failed([Violation::ofExchange(sprintf(
                'sent %s, which the contract does not document as a request body (it documents %s)',
                $requested ?? 'no content type',
                implode(', ', array_map(strval(...), array_keys($content))),
            ), 'the request')]);
        }

        return $this->body(
            $exchange->requestBody,
            $content[$key],
            [...$segments, 'content', $key, 'schema'],
            $key,
            'the request body',
            'the request',
            $exchange->ambiguousEmptyRequestBody,
        );
    }

    /**
     * Decode a JSON payload and check it against the media type's schema.
     *
     * @param  list<string>  $schemaSegments
     * @param  bool  $ambiguousEmpty  whether a `[]` in these bytes could as easily have been `{}`
     *                                ({@see Exchange::__construct()}), which only the producer knows
     */
    private function body(string $raw, mixed $media, array $schemaSegments, string $mediaType, string $location, string $half, bool $ambiguousEmpty = false): Outcome
    {
        if (! MediaType::isJson($mediaType)) {
            return Outcome::passed(sprintf('%s is %s, which JSON Schema cannot check', $location, $mediaType));
        }

        $schema = is_array($media) ? ($media['schema'] ?? null) : null;

        if (! is_array($schema) && ! is_bool($schema)) {
            return Outcome::passed(sprintf('the contract documents no schema for %s (%s)', $location, $mediaType));
        }

        if (trim($raw) === '') {
            return Outcome::failed([Violation::ofExchange(sprintf(
                '%s is empty, but the contract documents a %s body',
                $location,
                $mediaType,
            ), $half)]);
        }

        try {
            $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return Outcome::failed([Violation::ofExchange(sprintf('%s is not valid JSON: %s', $location, $exception->getMessage()), $half)]);
        }

        return Outcome::failedOrPassed($this->validate(
            $ambiguousEmpty ? $this->readEmpty($data, $schemaSegments) : $data,
            $schemaSegments,
            $location,
        ));
    }

    /**
     * An empty JSON array, from a producer that has no other way to write an empty JSON object, read as
     * whichever of the two the contract accepts here.
     *
     * The widening is offered to the very schema the body is about to be held to, and taken only where
     * that schema says yes — so nothing the document rejects starts passing. `[]` against an array with
     * `minItems: 1` is still the array it looks like and still fails; so is `[]` against an object whose
     * properties are required, which is the reading that names what is actually missing.
     *
     * @param  list<string>  $schemaSegments
     */
    private function readEmpty(mixed $data, array $schemaSegments): mixed
    {
        if ($data !== []) {
            return $data;
        }

        $empty = new stdClass;

        // The label never reaches a reader: these violations are the QUESTION — does the contract take
        // an empty object here — and are discarded either way.
        return $this->validate($empty, $schemaSegments, 'the empty object') === [] ? $empty : $data;
    }

    /**
     * {@see SchemaCheck} parses each schema as it reaches it, so one it will not parse — a `$ref` at a
     * name nothing defines, and `#/definitions/…` is the everyday one, since that is what an artifact
     * converted from Swagger 2.0 carries — throws rather than answering. Passing that off as a note
     * would put the check back where a dangling reference reports success, so it fails, naming the
     * pointer that went nowhere.
     *
     * @param  list<string>  $schemaSegments
     * @return list<Violation>
     */
    private function validate(mixed $data, array $schemaSegments, string $location): array
    {
        try {
            return $this->schema->check($data, $schemaSegments, $location);
        } catch (Throwable $refused) {
            return [new Violation(
                location: $location,
                pointer: '',
                message: 'could not be checked against the contract: '.RefusedSchema::reason($refused),
                schemaPointer: Pointer::of($schemaSegments),
                provenance: ProvenanceTrail::at($this->index->document(), $schemaSegments),
            )];
        }
    }

    /**
     * One half's notes as the single note an {@see Outcome} carries. Several uncheckable things in one
     * half read as one sentence per finding rather than as one finding surviving.
     */
    private static function note(?string ...$notes): ?string
    {
        $kept = array_values(array_filter($notes, static fn (?string $note): bool => $note !== null));

        return $kept === [] ? null : implode('; ', $kept);
    }
}
