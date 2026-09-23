<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

/**
 * `x-docuccino.generator` — what built the document, never anything about the API: the tool, the
 * extension spec version it wrote, and the URL that spec is served at. Excluded from `contentHash`, so
 * a tool or spec upgrade never dirties a committed diff.
 *
 * `schema` is nullable because a document read back from an artifact written before the member existed
 * has none, and naming a URL it never declared would be a lie.
 *
 * @internal
 */
final readonly class Generator
{
    public function __construct(
        public string $name,
        public string $version,
        public string $specVersion,
        public ?string $schema = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? '';
        $version = $data['version'] ?? '';
        $specVersion = $data['specVersion'] ?? '';
        $schema = $data['schema'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
            version: is_string($version) ? $version : '',
            specVersion: is_string($specVersion) ? $specVersion : '',
            schema: is_string($schema) ? $schema : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'version' => $this->version,
            'specVersion' => $this->specVersion,
        ];

        if ($this->schema !== null) {
            $out['schema'] = $this->schema;
        }

        return $out;
    }
}
