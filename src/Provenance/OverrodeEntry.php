<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * A single shadowed contribution kept for the "why is it documented this way" trail.
 */
final readonly class OverrodeEntry
{
    public function __construct(
        public string $field,
        public mixed $value = null,
        public ?string $producer = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $field = $data['field'] ?? '';
        $producer = $data['producer'] ?? null;

        return new self(
            field: is_string($field) ? $field : '',
            value: $data['value'] ?? null,
            producer: is_string($producer) ? $producer : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['field' => $this->field];

        if ($this->value !== null) {
            $out['value'] = $this->value;
        }

        if ($this->producer !== null) {
            $out['producer'] = $this->producer;
        }

        return $out;
    }
}
