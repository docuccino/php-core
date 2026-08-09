<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

/**
 * The compiled facts for one narrative page: {@see ContentCompiler} reads the markdown tree and
 * frontmatter, {@see ContentResolver} takes it from there. The adapter only supplies the content
 * directory path.
 *
 * `navType` picks how the page appears in the nav: `page` links to itself, `operation`/`tag` make a
 * reference node whose `navRef` resolves against the assembled document. `hidden` keeps the page in
 * the registry but out of the nav.
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
