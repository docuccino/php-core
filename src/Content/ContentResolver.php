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
 * an already-assembled document (design §Narrative content layer). This is the document-input half
 * of the content pipeline; like the compiler it lives in core: assigns each page its stable `page:`
 * id, resolves
 * `::operation`/`::schema` directives in the body, builds the deterministic nav tree from the
 * folder-derived groups + frontmatter overrides, and validates operation/tag nav refs — every
 * broken reference surfacing as a diagnostic rather than a silent drop.
 *
 * @internal
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
        // Drain any build-time index warnings (duplicate operationId collisions) into the document.
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
     * Build the nav tree: each non-hidden page becomes a node (a page link, or an operation/tag
     * reference), grouped by its folder-derived (or frontmatter-overridden) group, and ordered
     * deterministically — explicit `nav.order`, then title, then slug; groups by their least child
     * order then name.
     *
     * @param  array<string, string>  $navByPage  slug → page id (only pages that survived dedup)
     * @param  list<Diagnostic>  $diagnostics
     * @return list<NavNode>
     */
    private function buildNav(CompiledContent $content, array $navByPage, DocumentIndex $index, array &$diagnostics): array
    {
        /** @var list<array{sort: array{0: int, 1: string, 2: string}, group: ?string, node: NavNode}> $entries */
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
                'node' => $node,
            ];
        }

        return $this->assembleTree($entries);
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
     * @param  list<array{sort: array{0: int, 1: string, 2: string}, group: ?string, node: NavNode}>  $entries
     * @return list<NavNode>
     */
    private function assembleTree(array $entries): array
    {
        /** @var array<string, array{sort: array{0: int, 1: string}, children: list<array{sort: array{0: int, 1: string, 2: string}, node: NavNode}>}> $groups */
        $groups = [];
        /** @var list<array{sort: array{0: int, 1: string, 2: string}, node: NavNode}> $roots */
        $roots = [];

        foreach ($entries as $entry) {
            if ($entry['group'] === null || $entry['group'] === '') {
                $roots[] = ['sort' => $entry['sort'], 'node' => $entry['node']];

                continue;
            }

            $name = $entry['group'];
            if (! isset($groups[$name])) {
                $groups[$name] = ['sort' => [PHP_INT_MAX, $name], 'children' => []];
            }
            // A group sorts by its least child order, then its name.
            $groups[$name]['sort'] = [min($groups[$name]['sort'][0], $entry['sort'][0]), $name];
            $groups[$name]['children'][] = ['sort' => $entry['sort'], 'node' => $entry['node']];
        }

        /** @var list<array{sort: array{0: int, 1: string}, node: NavNode}> $rootEntries */
        $rootEntries = [];

        foreach ($roots as $root) {
            $rootEntries[] = ['sort' => [$root['sort'][0], $root['sort'][1]], 'node' => $root['node']];
        }

        foreach ($groups as $name => $group) {
            usort($group['children'], static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
            $children = array_map(static fn (array $child): NavNode => $child['node'], $group['children']);
            $rootEntries[] = ['sort' => $group['sort'], 'node' => new NavNode(type: 'group', title: $name, children: $children)];
        }

        usort($rootEntries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(static fn (array $entry): NavNode => $entry['node'], $rootEntries);
    }
}
