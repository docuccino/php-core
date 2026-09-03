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
     * @param  list<mixed>  $values  every value sent under that name; either half may have sent a
     *                               header more than once
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

        $schema = $parameter->schema();

        return match ($schema->kind) {
            ParameterSchemaKind::Schema => [$this->checkValues($parameter, $schema, $values), null],
            ParameterSchemaKind::Content => [[], sprintf('%s is documented as a content object, which the check does not decode', $parameter->label())],
            ParameterSchemaKind::Malformed => [[], sprintf('%s is documented with a declaration this check cannot read', $parameter->label())],
            ParameterSchemaKind::Absent => [[], sprintf('the contract documents no schema for %s', $parameter->label())],
        };
    }

    /**
     * EVERY value the name carried, against the one schema the contract publishes for it — so a name
     * sent twice cannot satisfy the contract with one value and violate it with the other. Several
     * values label themselves apart (`header X-Trace (value 2)`); one reads as the parameter itself.
     *
     * @param  list<mixed>  $values
     * @return list<Violation>
     */
    private function checkValues(ContractParameter $parameter, ParameterSchema $schema, array $values): array
    {
        $violations = [];
        foreach ($values as $index => $value) {
            $label = count($values) === 1 ? $parameter->label() : sprintf('%s (value %d)', $parameter->label(), $index + 1);

            foreach ($this->validate(
                $schema->read($value, $this->index->document()),
                $parameter->schemaSegments(),
                $label,
            ) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
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
            $values = match ($parameter->in) {
                'path' => self::sent($bound[$parameter->name] ?? null),
                'query' => self::sent($exchange->query[$parameter->name] ?? null),
                // Every value, not the first: a request may send one name twice, and what the contract
                // says the header looks like it says of each of them — the response half's rule,
                // because it was never a rule about responses.
                'header' => $exchange->header($parameter->name),
                'cookie' => self::sent($exchange->cookies[$parameter->name] ?? null),
                default => [],
            };

            [$found, $note] = $this->checkParameter(
                $parameter,
                $values,
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

    /**
     * What the exchange carried at a location that can only ever have carried one thing — a path
     * segment, a query key, a cookie — in the shape {@see checkParameter()} takes.
     *
     * @return list<mixed>
     */
    private static function sent(mixed $value): array
    {
        return $value === null ? [] : [$value];
    }

    /**
     * The documented request body against what the request sent — as bytes, or as the fields a form
     * body was parsed into ({@see Exchange}).
     *
     * A body that is absent and a media type that is undocumented are reported TOGETHER rather than
     * the first winning. They are two independent mistakes, and a request that made both used to be
     * sent away to fix one and come straight back on the other — which reads as the check moving the
     * goalposts. The one pairing left unsaid is an absent body with no declared type: there is no type
     * there to be undocumented, and naming one would be a second sentence about a single mistake.
     */
    private function requestBody(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $documented = $operation->requestBody($this->index->document());

        if ($documented === null) {
            // Nothing written and something written this cannot read are both "no body was checked",
            // and they are different facts: the first is an operation that promises nothing about a
            // body, the second a promise nobody looked at. Passing both in silence is how the second
            // reads as the first ({@see Refs::malformed()}).
            return Refs::malformed($operation->operation, 'requestBody')
                ? Outcome::passed('the contract documents a request body this check cannot read')
                : Outcome::passed();
        }

        [$body, $segments, $dangling] = $documented;

        if ($dangling !== null) {
            return Outcome::failed([Violation::unresolvedRef($dangling, 'the request body')]);
        }

        // Nobody can say what this request sent, so nothing about it is checked and the whole half says
        // so: an empty body would not be evidence the request sent none, and the type it declared would
        // not be evidence of what it was ({@see Exchange::$requestBodyUnread}).
        if ($exchange->requestBodyUnread !== null) {
            return Outcome::passed('the request body could not be read: '.$exchange->requestBodyUnread);
        }

        $form = $exchange->requestForm;
        $absent = $form === null && trim($exchange->requestBody) === '';

        if ($absent && ($body['required'] ?? false) !== true) {
            return Outcome::passed();
        }

        $content = $body['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return $absent
                ? Outcome::failed([self::sentNoBody()])
                : Outcome::passed('the contract documents a request body with no media types');
        }

        /** @var array<string, mixed> $content */
        $requested = MediaType::base($exchange->requestContentType);
        $key = MediaType::select($content, $requested);

        if ($key === null) {
            $violations = $absent ? [self::sentNoBody()] : [];

            if (! $absent || $requested !== null) {
                $violations[] = Violation::ofExchange(sprintf(
                    'sent %s, which the contract does not document as a request body (it documents %s)',
                    $requested ?? 'no content type',
                    implode(', ', array_map(strval(...), array_keys($content))),
                ), 'the request');
            }

            return Outcome::failed($violations);
        }

        if ($absent) {
            return Outcome::failed([self::sentNoBody()]);
        }

        $schemaSegments = [...$segments, 'content', $key, 'schema'];

        // On what ARRIVED rather than on what the key is called: a request the framework parsed into
        // fields has no bytes left to decode, whether the entry describing it is `multipart/form-data`
        // or the `*/*` that also matched it.
        if ($form !== null) {
            return $this->formBody($form, $content[$key], $schemaSegments, $key, 'the request body');
        }

        // The other way round, and only on this half: a form body IS checkable as its fields, so one
        // that arrives as bytes arrived as a message nothing decoded. Saying JSON Schema cannot check
        // it would name the wrong reason — and a form-typed RESPONSE really is bytes, which is why the
        // sentence lives here rather than in {@see body()}.
        if (MediaType::isForm($key)) {
            return Outcome::passed(sprintf(
                'the request body is %s, and reached the check as bytes rather than as the fields its schema describes',
                $key,
            ));
        }

        return $this->body(
            $exchange->requestBody,
            $content[$key],
            $schemaSegments,
            $key,
            'the request body',
            'the request',
            $exchange->ambiguousEmptyRequestBody,
        );
    }

    private static function sentNoBody(): Violation
    {
        return Violation::ofExchange('sent no request body, which the contract documents as required', 'the request');
    }

    /**
     * A form body against the schema the document publishes for it.
     *
     * There is nothing special about the schema: an OpenAPI form body is an ordinary object schema, and
     * a `format: binary` part is an ordinary string. What is special is the VALUES, which were strings
     * on the wire whatever the document calls them — `quantity=2` is `"2"` for the same reason
     * `?quantity=2` is — so they are read back through {@see ParameterValue::coerceForm()}, which
     * converts only where the string cannot stand as itself.
     *
     * @param  array<array-key, mixed>  $form
     * @param  list<string>  $schemaSegments
     */
    private function formBody(array $form, mixed $media, array $schemaSegments, string $mediaType, string $location): Outcome
    {
        $schema = self::mediaSchema($media);

        if ($schema === null) {
            return Outcome::passed(self::noSchemaNote($location, $mediaType));
        }

        return Outcome::failedOrPassed($this->validate(
            ParameterValue::coerceForm($form, is_array($schema) ? $schema : null, $this->index->document()),
            $schemaSegments,
            $location,
        ));
    }

    /**
     * The schema the document publishes for a media type, or null where it publishes none. One reading
     * for both bodies: a note that fired on one road and not the other would be a body checked in
     * silence on whichever road forgot it.
     *
     * @return array<string, mixed>|bool|null
     */
    private static function mediaSchema(mixed $media): array|bool|null
    {
        $schema = is_array($media) ? ($media['schema'] ?? null) : null;

        if (is_bool($schema)) {
            return $schema;
        }

        /** @var array<string, mixed>|null */
        return is_array($schema) ? $schema : null;
    }

    private static function noSchemaNote(string $location, string $mediaType): string
    {
        return sprintf('the contract documents no schema for %s (%s)', $location, $mediaType);
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

        if (self::mediaSchema($media) === null) {
            return Outcome::passed(self::noSchemaNote($location, $mediaType));
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
