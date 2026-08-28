<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One webhook of the documented contract: the name it is published under, the method the receiving
 * endpoint implements, and the raw OAS operation object.
 *
 * A webhook is found by NAME — it is a keyed entry of the document's `webhooks` map, and nothing about
 * it is routable, so none of the path-matching a {@see ContractOperation} carries applies to it.
 */
final readonly class ContractWebhook
{
    /**
     * @param  string|null  $id  the `x-docuccino.id`, absent only when the artifact carries no identities
     * @param  array<string, mixed>  $operation
     * @param  list<string>  $segments  document pointer segments addressing this operation
     */
    public function __construct(
        public ?string $id,
        public string $name,
        public string $method,
        public array $operation,
        public array $segments,
    ) {}

    /** `POST webhooks.invoice.paid` — the vocabulary the diff, the lints and the diagnostics use. */
    public function label(): string
    {
        return strtoupper($this->method).' webhooks.'.$this->name;
    }

    /**
     * The documented delivery body and the segments addressing it, `$ref` followed.
     *
     * @param  array<string, mixed>  $document
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}|null
     */
    public function requestBody(array $document): ?array
    {
        return Refs::member($document, $this->operation, 'requestBody', $this->segments);
    }
}
