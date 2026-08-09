<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

/**
 * Emitter options shared by the OAS 3.2 and 3.1 emitters.
 *
 * - `keepIds`: re-emit each node's `x-docuccino.id` as a flat `x-docuccino-id` member (default: drop).
 * - `mockFakerKey`: map schema `x-docuccino.mock.faker` to this member (e.g. `x-faker`); `null`
 *   drops mock hints (the default — pure OpenAPI carries no mock metadata).
 * - `provenance`: retained for symmetry with UIR emission; OAS emitters ignore it (they never
 *   emit provenance).
 * - `yaml`: emit YAML instead of JSON.
 */
final readonly class EmitOptions
{
    public function __construct(
        public bool $keepIds = false,
        public ?string $mockFakerKey = null,
        public ProvenanceLevel $provenance = ProvenanceLevel::None,
        public bool $yaml = false,
    ) {}

    public function withKeepIds(bool $keepIds = true): self
    {
        return new self($keepIds, $this->mockFakerKey, $this->provenance, $this->yaml);
    }

    public function withMockFakerKey(?string $key): self
    {
        return new self($this->keepIds, $key, $this->provenance, $this->yaml);
    }

    public function withProvenance(ProvenanceLevel $provenance): self
    {
        return new self($this->keepIds, $this->mockFakerKey, $provenance, $this->yaml);
    }

    public function withYaml(bool $yaml = true): self
    {
        return new self($this->keepIds, $this->mockFakerKey, $this->provenance, $yaml);
    }
}
