<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

use Docuccino\Core\Support\Hydrate;

/**
 * An ordered list of provenance records attached to a UIR node.
 */
final readonly class Provenance
{
    /**
     * @param  list<ProvenanceRecord>  $records
     */
    public function __construct(
        public array $records = [],
    ) {}

    /**
     * @param  array<mixed, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Hydrate::listOf($data, ProvenanceRecord::fromArray(...)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ProvenanceRecord $record): array => $record->toArray(),
            $this->records,
        );
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }
}
