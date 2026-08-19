<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One operation of the documented contract: its stable `x-docuccino.id`, the method and path template
 * it answers, and the raw OAS operation object.
 *
 * Path-item-level parameters are already merged into {@see $parameters} (an operation-level parameter
 * of the same name and location wins, as OAS requires), so a caller never has to remember the
 * inheritance rule.
 */
final readonly class ContractOperation
{
    private PathTemplate $template;

    /**
     * @param  string|null  $id  the `x-docuccino.id`, absent only when the artifact carries no identities
     * @param  string  $path  the path TEMPLATE, `/api/invoices/{invoice}`
     * @param  array<string, mixed>  $operation
     * @param  list<ContractParameter>  $parameters
     * @param  list<string>  $segments  document pointer segments addressing this operation
     */
    public function __construct(
        public ?string $id,
        public string $method,
        public string $path,
        public array $operation,
        public array $parameters,
        public array $segments,
    ) {
        $this->template = PathTemplate::parse($path);
    }

    /** `GET /api/invoices/{invoice}` — how a failure message and a coverage row name the operation. */
    public function label(): string
    {
        return $this->method.' '.$this->path;
    }

    /**
     * The path parameters a concrete request path binds to this template, or null when the template
     * does not describe that path at all.
     *
     * @return array<string, string>|null
     */
    public function bind(string $path): ?array
    {
        return $this->template->bind($path);
    }

    /**
     * How specific this template is, for choosing between two that both matched — `/api/invoices/recent`
     * beats `/api/invoices/{invoice}`. Comparable as a string against another matched template.
     *
     * @internal
     */
    public function literalMask(): string
    {
        return $this->template->literalMask();
    }

    /**
     * The documented response for a status code and the pointer segments that address it: the exact
     * code first, then the OAS range (`2XX`), then `default`. A `$ref` into `components/responses` is
     * followed, so the segments name where a reader would actually go and look.
     *
     * @param  array<string, mixed>  $document
     * @return array{0: array<string, mixed>, 1: list<string>}|null
     */
    public function responseFor(array $document, int $status): ?array
    {
        $responses = $this->operation['responses'] ?? null;

        if (! is_array($responses)) {
            return null;
        }

        foreach ([(string) $status, substr((string) $status, 0, 1).'XX', 'default'] as $key) {
            $response = $responses[$key] ?? null;

            if (is_array($response)) {
                /** @var array<string, mixed> $response */
                return Refs::follow($document, $response, [...$this->segments, 'responses', $key]);
            }
        }

        return null;
    }

    /** The documented status keys, for a "the contract documents 200, 404" message. */
    public function documentedStatuses(): string
    {
        $responses = $this->operation['responses'] ?? null;

        if (! is_array($responses) || $responses === []) {
            return 'none';
        }

        $keys = array_map(strval(...), array_keys($responses));
        sort($keys);

        return implode(', ', $keys);
    }

    /**
     * The documented request body and the segments addressing it, `$ref` followed.
     *
     * @param  array<string, mixed>  $document
     * @return array{0: array<string, mixed>, 1: list<string>}|null
     */
    public function requestBody(array $document): ?array
    {
        $body = $this->operation['requestBody'] ?? null;

        if (! is_array($body)) {
            return null;
        }

        /** @var array<string, mixed> $body */
        return Refs::follow($document, $body, [...$this->segments, 'requestBody']);
    }
}
