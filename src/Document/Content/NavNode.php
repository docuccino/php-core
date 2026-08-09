<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Content;

use Docuccino\Core\Support\Hydrate;

/**
 * One node of the compiled navigation tree (`x-docuccino.content.nav`) — the contract every viewer
 * renders its sidebar from, so guides and API reference interleave in one deterministic structure.
 *
 * `type` is one of:
 *  - `group`     — a section header with `children`, folder-derived unless frontmatter names it;
 *  - `page`      — a content page, `ref` = its `page:` id;
 *  - `operation` — an API operation, `ref` = its `op:` id (resolved at assembly);
 *  - `tag`       — an OAS tag, `ref` = the tag name.
 *
 * `ref`/`title`/`children` are each emitted only when they mean something for that type, keeping the
 * serialization minimal.
 *
 * @internal
 */
final readonly class NavNode
{
    /**
     * @param  list<NavNode>  $children
     */
    public function __construct(
        public string $type,
        public ?string $ref = null,
        public ?string $title = null,
        public array $children = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: Hydrate::stringOr($data['type'] ?? '', ''),
            ref: Hydrate::stringOrNull($data['ref'] ?? null),
            title: Hydrate::stringOrNull($data['title'] ?? null),
            children: Hydrate::listOf($data['children'] ?? null, self::fromArray(...)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type];

        if ($this->ref !== null) {
            $out['ref'] = $this->ref;
        }

        if ($this->title !== null) {
            $out['title'] = $this->title;
        }

        if ($this->children !== []) {
            $out['children'] = array_map(
                static fn (NavNode $child): array => $child->toArray(),
                $this->children,
            );
        }

        return $out;
    }
}
