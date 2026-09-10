<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\Content\ContentExtension;
use Docuccino\Core\Document\Content\NavNode;
use Docuccino\Core\Document\Content\Page;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Provenance\ProvenanceRecord;
use Docuccino\Core\Provenance\Source;

/**
 * Turns the compiled {@see CompiledContent} into the resolved `x-docuccino.content` layer against
 * an already-assembled document: assigns each page its stable `page:` id, resolves
 * `::operation`/`::schema` directives in the body, builds the nav tree from folder-derived groups
 * plus frontmatter overrides, and validates operation/tag nav refs. Every broken reference becomes
 * a diagnostic, never a silent drop.
 *
 * @internal
 *
 * @phpstan-type NavEntry array{sort: array{0: int, 1: string, 2: string}, group: ?string, page: CompiledPage, node: NavNode}
 * @phpstan-type NavPlacement array{sort: array{0: int, 1: string}, node: NavNode}
 */
final readonly class ContentResolver
{
    public function __construct(
        private IdentityGenerator $identity = new IdentityGenerator,
        private DirectiveResolver $directives = new DirectiveResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $document  the assembled UIR document array
     * @return array{0: ContentExtension, 1: list<Diagnostic>}
     */
    public function resolve(CompiledContent $content, array $document): array
    {
        if ($content->isEmpty()) {
            return [new ContentExtension, []];
        }

        $index = DocumentIndex::build($document);
        // Index build-time warnings (duplicate operationId collisions) go into the document too.
        $diagnostics = $index->diagnostics();

        $pages = [];
        $navByPage = [];
        $seenSlugs = [];

        foreach ($content->pages as $compiled) {
            if (isset($seenSlugs[$compiled->slug])) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Error,
                    code: 'content.duplicate-slug',
                    message: sprintf('Two content pages share the slug "%s"; the later one is ignored.', $compiled->slug),
                    source: new Source($compiled->sourceFile),
                );

                continue;
            }
            $seenSlugs[$compiled->slug] = true;

            $pageId = $this->identity->pageId($compiled->slug);

            [$body, $directiveDiagnostics] = $this->directives->resolve($compiled->body, $compiled->slug, $compiled->sourceFile, $index);
            foreach ($directiveDiagnostics as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }

            $pages[] = new Page(
                id: $pageId,
                slug: $compiled->slug,
                title: $compiled->title,
                summary: $compiled->summary,
                order: $compiled->order,
                tags: $compiled->tags,
                content: $body === '' ? null : $body,
                provenance: [new ProvenanceRecord(producer: 'config', layer: 'config', source: new Source($compiled->sourceFile))],
            );

            $navByPage[$compiled->slug] = $pageId;
        }

        $nav = $this->buildNav($content, $navByPage, $index, $diagnostics);

        return [new ContentExtension(pages: $pages, nav: $nav), $diagnostics];
    }

    /**
     * Each non-hidden page becomes a node — a page link, or an operation/tag reference — grouped by
     * its folder-derived (or frontmatter-overridden) group. Order is explicit `nav.order`, then
     * title, then slug; groups sort by their least child order, then name.
     *
     * @param  array<string, string>  $navByPage  slug → page id (only pages that survived dedup)
     * @param  list<Diagnostic>  $diagnostics
     * @return list<NavNode>
     */
    private function buildNav(CompiledContent $content, array $navByPage, DocumentIndex $index, array &$diagnostics): array
    {
        /** @var list<NavEntry> $entries */
        $entries = [];

        foreach ($content->pages as $compiled) {
            $pageId = $navByPage[$compiled->slug] ?? null;
            if ($pageId === null || $compiled->hidden) {
                continue; // dropped by dedup, or intentionally hidden from the nav
            }

            $node = $this->navNodeFor($compiled, $pageId, $index, $diagnostics);
            if ($node === null) {
                continue; // a broken operation/tag ref — already diagnosed
            }

            $entries[] = [
                'sort' => [$compiled->order ?? PHP_INT_MAX, (string) ($compiled->title ?? $compiled->slug), $compiled->slug],
                'group' => $compiled->group,
                'page' => $compiled,
                'node' => $node,
            ];
        }

        return $this->assembleTree($entries, $diagnostics);
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private function navNodeFor(CompiledPage $compiled, string $pageId, DocumentIndex $index, array &$diagnostics): ?NavNode
    {
        return match ($compiled->navType) {
            'operation' => $this->refNode('operation', $compiled, static fn (string $ref): ?string => $index->resolveOperation($ref), $diagnostics),
            'tag' => $this->refNode('tag', $compiled, static fn (string $ref): ?string => $index->hasTag($ref) ? $ref : null, $diagnostics),
            default => new NavNode(type: 'page', ref: $pageId, title: $compiled->title),
        };
    }

    /**
     * @param  callable(string): ?string  $lookup
     * @param  list<Diagnostic>  $diagnostics
     */
    private function refNode(string $type, CompiledPage $compiled, callable $lookup, array &$diagnostics): ?NavNode
    {
        $ref = $compiled->navRef ?? '';
        $resolved = $ref === '' ? null : $lookup($ref);

        if ($resolved === null) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Error,
                code: 'content.unresolved-nav-ref',
                message: sprintf('The %s nav node on page "%s" references "%s", which resolves to nothing.', $type, $compiled->slug, $ref),
                source: new Source($compiled->sourceFile),
            );

            return null;
        }

        return new NavNode(type: $type, ref: $resolved, title: $compiled->title);
    }

    /**
     * Fold the flat, per-page entries into a grouped, ordered tree.
     *
     * @param  list<NavEntry>  $entries
     * @param  list<Diagnostic>  $diagnostics
     * @return list<NavNode>
     */
    private function assembleTree(array $entries, array &$diagnostics): array
    {
        /** @var array<string, non-empty-list<NavEntry>> $groups */
        $groups = [];
        /** @var list<NavEntry> $roots */
        $roots = [];

        foreach ($entries as $entry) {
            if ($entry['group'] === null || $entry['group'] === '') {
                $roots[] = $entry;

                continue;
            }

            $groups[$entry['group']][] = $entry;
        }

        /** @var list<NavPlacement> $rootEntries */
        $rootEntries = [];

        foreach ($this->ordered($roots, $diagnostics) as $root) {
            $rootEntries[] = ['sort' => [$root['sort'][0], $root['sort'][1]], 'node' => $root['node']];
        }

        foreach ($groups as $name => $members) {
            $children = $this->ordered($members, $diagnostics);

            // A group sorts by its least member order, then its name. Reading it off the members and
            // reading it off the children it keeps are the same answer: `ordered()` sorts first and
            // holds nothing back at the entry that sorts first, so the least-ordered member is always
            // one of the children.
            $least = min(array_map(static fn (array $member): int => $member['sort'][0], $members));

            $rootEntries[] = [
                'sort' => [$least, $name],
                'node' => new NavNode(type: 'group', title: $name, children: array_map(static fn (array $child): NavNode => $child['node'], $children)),
            ];
        }

        usort($rootEntries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(static fn (array $entry): NavNode => $entry['node'], $rootEntries);
    }

    /**
     * One nav parent's entries, ordered and with each destination held once.
     *
     * Two pages may both ask to be the link to one operation or tag, and a sidebar drawing the same
     * destination twice in one section is a section with a dead-looking twin in it. The one kept is the
     * one the parent's own order puts first — explicit `nav.order`, then title, then slug, which is
     * total because slugs are unique — so it is a function of the pages contesting the link and not of
     * the order they were compiled in. The whole losing node goes rather than a member of it: each node
     * is one page's statement about itself, so blending two would publish a link neither page asked for.
     *
     * Deduping per parent, not across the tree: one endpoint surfaced under two sections is a
     * navigation an author can reasonably want, and there would be no other way to write it.
     *
     * @param  list<NavEntry>  $entries
     * @param  list<Diagnostic>  $diagnostics
     * @return list<NavEntry>
     */
    private function ordered(array $entries, array &$diagnostics): array
    {
        usort($entries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $kept = [];
        /** @var array<string, string> $held  destination → the slug holding it */
        $held = [];

        foreach ($entries as $entry) {
            $ref = $entry['node']->ref;
            // A `page` node refs its own page id, which slug dedup already made unique, so only an
            // operation or a tag can be named twice.
            $destination = $entry['node']->type.' '.$ref;

            if ($ref !== null && isset($held[$destination])) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'content.duplicate-nav-ref',
                    message: sprintf(
                        'Page "%s" adds a second %s nav entry for "%s" in the same part of the tree; "%s" already links there, so this one is left out.',
                        $entry['page']->slug,
                        $entry['node']->type,
                        $entry['page']->navRef ?? '',
                        $held[$destination],
                    ),
                    source: new Source($entry['page']->sourceFile),
                );

                continue;
            }

            $held[$destination] = $entry['page']->slug;
            $kept[] = $entry;
        }

        return $kept;
    }
}
