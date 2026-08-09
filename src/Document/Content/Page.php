<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Content;

use Docuccino\Core\Provenance\ProvenanceRecord;
use Docuccino\Core\Support\Hydrate;

/**
 * One narrative page in `x-docuccino.content.pages`: a stable `page:` id (hashed from the slug so it
 * survives file moves), its slug/title/summary metadata, search-facet `tags`, the compiled markdown
 * `content` (with directives already resolved to stable ids), and provenance pointing at the source
 * file. Diff/SaaS changelogs key on the id, so prose edits are visible changes.
 *
 * @internal
 */
final readonly class Page
{
    /**
     * @param  list<string>  $tags
     * @param  list<ProvenanceRecord>  $provenance
     */
    public function __construct(
        public string $id,
        public string $slug,
        public ?string $title = null,
        public ?string $summary = null,
        public ?int $order = null,
        public array $tags = [],
        public ?string $content = null,
        public array $provenance = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Hydrate::stringOr($data['id'] ?? '', ''),
            slug: Hydrate::stringOr($data['slug'] ?? '', ''),
            title: Hydrate::stringOrNull($data['title'] ?? null),
            summary: Hydrate::stringOrNull($data['summary'] ?? null),
            order: Hydrate::intOrNull($data['order'] ?? null),
            tags: Hydrate::stringList($data['tags'] ?? null),
            content: Hydrate::stringOrNull($data['content'] ?? null),
            provenance: Hydrate::listOf($data['provenance'] ?? null, ProvenanceRecord::fromArray(...)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'slug' => $this->slug];

        if ($this->title !== null) {
            $out['title'] = $this->title;
        }

        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }

        if ($this->order !== null) {
            $out['order'] = $this->order;
        }

        if ($this->tags !== []) {
            $out['tags'] = $this->tags;
        }

        if ($this->content !== null) {
            $out['content'] = $this->content;
        }

        if ($this->provenance !== []) {
            $out['provenance'] = array_map(
                static fn (ProvenanceRecord $record): array => $record->toArray(),
                $this->provenance,
            );
        }

        return $out;
    }
}
