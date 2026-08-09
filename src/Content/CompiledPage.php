<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

/**
 * The compiled facts for one narrative page, produced by core's {@see ContentCompiler} (which reads
 * the markdown tree + frontmatter) and handed to the core {@see ContentResolver}. Compilation, id
 * assignment, directive resolution against the assembled document and nav-tree building all live in
 * core — the adapter only supplies the content directory path.
 *
 * `navType` selects how the page appears in the nav tree: `page` (a link to itself), or `operation`
 * / `tag` (a reference node whose `navRef` resolves against the assembled document). `hidden` keeps
 * the page in the registry but drops it from the nav.
 *
 * @internal
 */
final readonly class CompiledPage
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $slug,
        public string $body,
        public string $sourceFile,
        public string $sourceHash,
        public ?string $title = null,
        public ?string $summary = null,
        public ?int $order = null,
        public array $tags = [],
        public ?string $group = null,
        public bool $hidden = false,
        public string $navType = 'page',
        public ?string $navRef = null,
    ) {}
}
