<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnknownT;

/**
 * A single property of a {@see ClassMetadata}: its name, resolved type, optional
 * docblock summary prose, an optional `@example` value, and where it is declared.
 * `initialised` says whether the constructor the class runs assigns it on every path that
 * completes (false: one completes without it); null where the engine did not look.
 */
final readonly class PropertyMetadata
{
    public function __construct(
        public string $name,
        public DType $type,
        public ?string $summary = null,
        public ?string $example = null,
        public ?SourceLocation $location = null,
        public ?bool $initialised = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['name' => $this->name, 'type' => $this->type->toArray()];

        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }

        if ($this->example !== null) {
            $out['example'] = $this->example;
        }

        if ($this->location !== null) {
            $out['location'] = $this->location->toArray();
        }

        if ($this->initialised !== null) {
            $out['initialised'] = $this->initialised;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? '';
        $type = $data['type'] ?? [];
        $summary = $data['summary'] ?? null;
        $example = $data['example'] ?? null;
        $location = $data['location'] ?? null;
        $initialised = $data['initialised'] ?? null;

        return new self(
            is_string($name) ? $name : '',
            is_array($type) ? DType::fromArray($type) : new UnknownT('malformed property type'),
            is_string($summary) ? $summary : null,
            is_string($example) ? $example : null,
            is_array($location) ? SourceLocation::fromArray($location) : null,
            is_bool($initialised) ? $initialised : null,
        );
    }
}
