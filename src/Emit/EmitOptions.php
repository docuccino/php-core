<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

/**
 * Emitter options shared by the OAS 3.2 and 3.1 emitters.
 *
 * - `keepIds`: re-emit each node's `x-docuccino.id` as a flat `x-docuccino-id` member (default: drop,
 *   so a bare emit stays pure OpenAPI and keeps the round-trip guarantee below). The id is an opaque
 *   truncated hash of members the document already publishes — method, normalised path, status, media
 *   type — so it discloses nothing the spec doesn't; provenance is the half that would (source file,
 *   line, symbol) and is dropped unconditionally. `docuccino:export` turns this ON by default, because
 *   an artifact you commit is one you will later diff, and identities are what make that diff semantic.
 * - `mockFakerKey`: map schema `x-docuccino.mock.faker` to this member (e.g. `x-faker`); `null`
 *   drops mock hints (the default — pure OpenAPI carries no mock metadata).
 * - `provenance`: retained for symmetry with UIR emission; OAS emitters ignore it (they never
 *   emit provenance).
 * - `yaml`: emit YAML instead of JSON.
 * - `formatSamples`: the document's `representation.examples.formats` map, for the emitters that
 *   FABRICATE a value the document does not state — today the Postman collection's request bodies,
 *   saved response examples and URL variables. It rides here rather than on the document because a
 *   fabricated illustration is a rendering of the contract, not part of it: the same sample the
 *   document's own synthesized examples used, arriving where the OAS artifact never needed it.
 */
final readonly class EmitOptions
{
    /**
     * @param  array<string, string>  $formatSamples  JSON Schema `format` => the sample to illustrate it with
     */
    public function __construct(
        public bool $keepIds = false,
        public ?string $mockFakerKey = null,
        public ProvenanceLevel $provenance = ProvenanceLevel::None,
        public bool $yaml = false,
        public array $formatSamples = [],
    ) {}

    public function withKeepIds(bool $keepIds = true): self
    {
        return new self($keepIds, $this->mockFakerKey, $this->provenance, $this->yaml, $this->formatSamples);
    }

    public function withMockFakerKey(?string $key): self
    {
        return new self($this->keepIds, $key, $this->provenance, $this->yaml, $this->formatSamples);
    }

    public function withProvenance(ProvenanceLevel $provenance): self
    {
        return new self($this->keepIds, $this->mockFakerKey, $provenance, $this->yaml, $this->formatSamples);
    }

    public function withYaml(bool $yaml = true): self
    {
        return new self($this->keepIds, $this->mockFakerKey, $this->provenance, $yaml, $this->formatSamples);
    }

    /**
     * @param  array<string, string>  $samples
     */
    public function withFormatSamples(array $samples): self
    {
        return new self($this->keepIds, $this->mockFakerKey, $this->provenance, $this->yaml, $samples);
    }
}
