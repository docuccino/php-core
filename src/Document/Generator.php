<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

/**
 * `x-docuccino.generator` — excluded from `contentHash` so tool upgrades never dirty diffs.
 *
 * @internal
 */
final readonly class Generator
{
    public function __construct(
        public string $name,
        public string $version,
        public string $specVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? '';
        $version = $data['version'] ?? '';
        $specVersion = $data['specVersion'] ?? '';

        return new self(
            name: is_string($name) ? $name : '',
            version: is_string($version) ? $version : '',
            specVersion: is_string($specVersion) ? $specVersion : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'specVersion' => $this->specVersion,
        ];
    }
}
