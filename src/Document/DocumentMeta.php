<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

/**
 * `x-docuccino.document` — document identity and content-addressing metadata.
 *
 * @internal
 */
final readonly class DocumentMeta
{
    public function __construct(
        public string $id,
        public ?string $configHash = null,
        public ?string $contentHash = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? '';
        $configHash = $data['configHash'] ?? null;
        $contentHash = $data['contentHash'] ?? null;

        return new self(
            id: is_string($id) ? $id : '',
            configHash: is_string($configHash) ? $configHash : null,
            contentHash: is_string($contentHash) ? $contentHash : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['id' => $this->id];

        if ($this->configHash !== null) {
            $out['configHash'] = $this->configHash;
        }

        if ($this->contentHash !== null) {
            $out['contentHash'] = $this->contentHash;
        }

        return $out;
    }

    public function withContentHash(string $contentHash): self
    {
        return new self($this->id, $this->configHash, $contentHash);
    }
}
