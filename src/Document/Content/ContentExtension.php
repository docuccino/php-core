<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Content;

use Docuccino\Core\Support\Hydrate;

/**
 * The narrative content layer (`x-docuccino.content`): the compiled `pages` registry and the `nav`
 * tree consumers render the sidebar from. A first-class UIR citizen — it participates in
 * `contentHash`, so a prose edit or a nav move is a visible (non-breaking) changelog entry.
 *
 * @internal
 */
final readonly class ContentExtension
{
    /**
     * @param  list<Page>  $pages
     * @param  list<NavNode>  $nav
     */
    public function __construct(
        public array $pages = [],
        public array $nav = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pages: Hydrate::listOf($data['pages'] ?? null, Page::fromArray(...)),
            nav: Hydrate::listOf($data['nav'] ?? null, NavNode::fromArray(...)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->pages === [] && $this->nav === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->pages !== []) {
            $out['pages'] = array_map(static fn (Page $page): array => $page->toArray(), $this->pages);
        }

        if ($this->nav !== []) {
            $out['nav'] = array_map(static fn (NavNode $node): array => $node->toArray(), $this->nav);
        }

        return $out;
    }
}
