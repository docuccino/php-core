<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance\Explain;

use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Patch\Remove;
use Docuccino\Core\Provenance\OverrodeEntry;
use Docuccino\Core\Provenance\Source;

/**
 * One layer's attempt at one field, as a reader sees it: who wrote it, at which rung, what value it
 * carried, and whether that value is the one the document publishes.
 *
 * `removed` is a write that resolved to field-absent ({@see Remove}) rather than a missing value:
 * the layer decided the field should not be there, which is a decision worth showing as one.
 *
 * A losing contribution keeps only what `overrode` recorded — a field, a value and a producer — so
 * its source is always null. That is a limit of the trail, not of this reader: {@see OverrodeEntry}
 * has nowhere to put one.
 *
 * @internal
 */
final readonly class FieldContribution
{
    public function __construct(
        public string $producer,
        public Layer $layer,
        public bool $won,
        public mixed $value = null,
        public ?Source $source = null,
        public ?float $confidence = null,
        public bool $removed = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'producer' => $this->producer,
            'layer' => $this->layer->label(),
            'rank' => $this->layer->value,
            'won' => $this->won,
        ];

        if ($this->removed) {
            $out['removed'] = true;
        }

        if ($this->value !== null) {
            $out['value'] = $this->value;
        }

        if ($this->source !== null) {
            $out['source'] = $this->source->toArray();
        }

        if ($this->confidence !== null) {
            $out['confidence'] = $this->confidence;
        }

        return $out;
    }
}
