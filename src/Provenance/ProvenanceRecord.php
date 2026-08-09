<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

use Docuccino\Core\Support\Hydrate;

/**
 * One contribution to a node: which producer/layer set which fields, from where,
 * with what confidence, and which lower values it shadowed.
 */
final readonly class ProvenanceRecord
{
    /**
     * @param  list<string>  $fields
     * @param  list<OverrodeEntry>  $overrode
     */
    public function __construct(
        public string $producer,
        public string $layer,
        public array $fields = [],
        public ?Source $source = null,
        public ?float $confidence = null,
        public array $overrode = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $producer = $data['producer'] ?? '';
        $layer = $data['layer'] ?? '';

        $fields = Hydrate::stringList($data['fields'] ?? null);
        $source = Hydrate::objectOrNull($data['source'] ?? null, Source::fromArray(...));

        $confidence = null;
        if (isset($data['confidence']) && (is_float($data['confidence']) || is_int($data['confidence']))) {
            $confidence = (float) $data['confidence'];
        }

        $overrode = Hydrate::listOf($data['overrode'] ?? null, OverrodeEntry::fromArray(...));

        return new self(
            producer: is_string($producer) ? $producer : '',
            layer: is_string($layer) ? $layer : '',
            fields: $fields,
            source: $source,
            confidence: $confidence,
            overrode: $overrode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'producer' => $this->producer,
            'layer' => $this->layer,
        ];

        if ($this->fields !== []) {
            $out['fields'] = $this->fields;
        }

        if ($this->source !== null) {
            $out['source'] = $this->source->toArray();
        }

        if ($this->confidence !== null) {
            $out['confidence'] = $this->confidence;
        }

        if ($this->overrode !== []) {
            $out['overrode'] = array_map(
                static fn (OverrodeEntry $entry): array => $entry->toArray(),
                $this->overrode,
            );
        }

        return $out;
    }
}
