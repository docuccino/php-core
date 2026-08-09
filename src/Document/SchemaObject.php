<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

/**
 * A JSON Schema 2020-12 object. The keyword space is open and recursive, so the raw
 * associative form is preserved wholesale; `x-docuccino` and any `x-*` members survive verbatim.
 *
 * @internal
 */
final readonly class SchemaObject
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function ref(): ?string
    {
        $ref = $this->data['$ref'] ?? null;

        return is_string($ref) ? $ref : null;
    }
}
