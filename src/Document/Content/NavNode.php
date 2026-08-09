<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Content;

use Docuccino\Core\Support\Hydrate;

/**
 * One node of the compiled navigation tree (`x-docuccino.content.nav`). The tree is the contract
 * every viewer/SaaS renders the sidebar from, so guides and API-reference sections interleave
 * Stripe-style in one deterministic structure.
 *
 * Node `type` is one of:
 *  - `group`    — a section header with `children` (folder-derived by default, frontmatter-named);
 *  - `page`     — a link to a content page, `ref` = its `page:` id;
 *  - `operation`— a link to an API operation, `ref` = its stable `op:` id (resolved at assembly);
 *  - `tag`      — a link to an OAS tag, `ref` = the tag name.
 *
 * `ref`/`title`/`children` are each emitted only when meaningful for the node's type, so the
 * serialization stays minimal and canonical.
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
