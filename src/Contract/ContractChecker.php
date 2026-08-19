<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use JsonException;

/**
 * The OAS half of contract checking: match an {@see Exchange} to its operation, pick the documented
 * response for the status, the `content` entry for the media type and the parameters for the request,
 * then hand each payload to {@see SchemaCheck}.
 *
 * Where the contract cannot be checked rather than being wrong — a `text/csv` body, a media type with
 * no schema — the outcome passes with a NOTE rather than passing silently. A pass that proved nothing
 * and says nothing is how a suite ends up believing it has contract coverage it does not have.
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

        [$response, $segments] = $documented;
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
        $violations = $this->parameters($operation, $exchange);
        $body = $this->requestBody($operation, $exchange);

        foreach ($body->violations as $violation) {
            $violations[] = $violation;
        }

        return $violations === [] ? Outcome::passed($body->note) : Outcome::failed($violations);
    }

    /**
     * @return list<Violation>
     */
    private function parameters(ContractOperation $operation, Exchange $exchange): array
    {
        $bound = $operation->bind($exchange->path) ?? [];

        $violations = [];
        foreach ($operation->parameters as $parameter) {
            $value = match ($parameter->in) {
                'path' => $bound[$parameter->name] ?? null,
                'query' => $exchange->query[$parameter->name] ?? null,
                'header' => $exchange->header($parameter->name),
                'cookie' => $exchange->cookies[$parameter->name] ?? null,
                default => null,
            };

            if ($value === null) {
                // A missing PATH parameter is not a contract violation: the request could not have
                // matched this template without one, so its absence means the template did not bind.
                if ($parameter->required && $parameter->in !== 'path') {
                    $violations[] = new Violation(
                        location: $parameter->label(),
                        pointer: '',
                        message: 'is documented as required, but the request did not send it',
                        schemaPointer: Pointer::of($parameter->segments),
                        provenance: ProvenanceTrail::at($this->index->document(), $parameter->segments),
                    );
                }

                continue;
            }

            foreach ($this->schema->check(
                ParameterValue::coerce($value, $parameter->schema()),
                $parameter->schemaSegments(),
                $parameter->label(),
            ) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function requestBody(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $documented = $operation->requestBody($this->index->document());

        if ($documented === null) {
            return Outcome::passed();
        }

        [$body, $segments] = $documented;

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
        );
    }

    /**
     * Decode a JSON payload and check it against the media type's schema.
     *
     * @param  list<string>  $schemaSegments
     */
    private function body(string $raw, mixed $media, array $schemaSegments, string $mediaType, string $location, string $half): Outcome
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

        return Outcome::failedOrPassed($this->schema->check($data, $schemaSegments, $location));
    }
}
