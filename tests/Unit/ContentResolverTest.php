<?php

declare(strict_types=1);

use Docuccino\Core\Content\CompiledContent;
use Docuccino\Core\Content\CompiledPage;
use Docuccino\Core\Content\ContentResolver;
use Docuccino\Core\Content\DirectiveResolver;
use Docuccino\Core\Content\DocumentIndex;
use Docuccino\Core\Diagnostics\Severity;

/**
 * A minimal assembled document to resolve references against: one operation (named + signature
 * addressable), one component schema, one tag.
 *
 * @return array<string, mixed>
 */
function contentIndexDoc(): array
{
    return [
        'paths' => [
            '/api/forms' => ['get' => [
                'operationId' => 'forms.index',
                'tags' => ['Forms'],
                'x-docuccino' => ['id' => 'op:v1:formsindex00000'],
            ]],
        ],
        'components' => ['schemas' => [
            'FormData' => ['x-docuccino' => ['id' => 'sch:v1:formdata0000000'], 'type' => 'object'],
        ]],
        'tags' => [['name' => 'Forms']],
    ];
}

function makePage(string $slug, array $overrides = []): CompiledPage
{
    return new CompiledPage(
        slug: $slug,
        body: $overrides['body'] ?? '',
        sourceFile: $overrides['sourceFile'] ?? $slug.'.md',
        sourceHash: $overrides['sourceHash'] ?? hash('sha256', $slug),
        title: $overrides['title'] ?? null,
        order: $overrides['order'] ?? null,
        group: $overrides['group'] ?? null,
        hidden: $overrides['hidden'] ?? false,
        navType: $overrides['navType'] ?? 'page',
        navRef: $overrides['navRef'] ?? null,
    );
}

// ---- DocumentIndex + DirectiveResolver -----------------------------------------------------------

it('resolves and degrades directives', function (string $body, string $expected, ?string $code, ?Severity $severity): void {
    $index = DocumentIndex::build(contentIndexDoc());

    [$rewritten, $diagnostics] = (new DirectiveResolver)->resolve($body, 'slug', 'slug.md', $index);

    expect($rewritten)->toBe($expected);

    if ($code === null) {
        expect($diagnostics)->toBe([]);
    } else {
        expect($diagnostics[0]->code)->toBe($code)
            ->and($diagnostics[0]->severity)->toBe($severity)
            ->and($diagnostics[0]->message)->toContain('slug');
    }
})->with([
    'operation by operationId' => [
        '::operation{id="forms.index"}',
        '::operation{id="forms.index" ref="op:v1:formsindex00000"}',
        null, null,
    ],
    'operation by method+path signature' => [
        '::operation{id="get /api/forms"}',
        '::operation{id="get /api/forms" ref="op:v1:formsindex00000"}',
        null, null,
    ],
    'schema by name' => [
        '::schema{name="FormData"}',
        '::schema{name="FormData" ref="sch:v1:formdata0000000"}',
        null, null,
    ],
    'broken operation ref' => [
        '::operation{id="ghost.missing"}',
        '::operation{id="ghost.missing"}',
        'content.unresolved-directive', Severity::Error,
    ],
    'broken schema ref' => [
        '::schema{name="Nope"}',
        '::schema{name="Nope"}',
        'content.unresolved-directive', Severity::Error,
    ],
    'missing selector attribute' => [
        '::operation{foo="bar"}',
        '::operation{foo="bar"}',
        'content.unresolved-directive', Severity::Error,
    ],
    'unknown directive degrades with a warning' => [
        '::widget{id="x"}',
        '::widget{id="x"}',
        'content.unknown-directive', Severity::Warning,
    ],
    'already-resolved directive is idempotent' => [
        '::operation{id="forms.index" ref="op:v1:formsindex00000"}',
        '::operation{id="forms.index" ref="op:v1:formsindex00000"}',
        null, null,
    ],
    // N3: a pre-existing ref is re-validated, not trusted — a stale one whose selector still resolves
    // is corrected to the fresh id...
    'stale ref is re-derived from its still-valid selector' => [
        '::operation{id="forms.index" ref="op:v1:0000000000000000"}',
        '::operation{id="forms.index" ref="op:v1:formsindex00000"}',
        null, null,
    ],
    // ...and one whose selector no longer resolves surfaces as unresolved rather than drifting.
    'stale ref on a now-missing selector is diagnosed' => [
        '::operation{id="ghost.missing" ref="op:v1:formsindex00000"}',
        '::operation{id="ghost.missing" ref="op:v1:formsindex00000"}',
        'content.unresolved-directive', Severity::Error,
    ],
    'a namespaced token is not a directive (guarded lookbehind)' => [
        'See foo::operation{id="forms.index"} inline.',
        'See foo::operation{id="forms.index"} inline.',
        null, null,
    ],
]);

it('leaves plain prose with no directives untouched and diagnostic-free', function (): void {
    $index = DocumentIndex::build(contentIndexDoc());

    [$rewritten, $diagnostics] = (new DirectiveResolver)->resolve('Just prose, no colons.', 's', 's.md', $index);

    expect($rewritten)->toBe('Just prose, no colons.')->and($diagnostics)->toBe([]);
});

it('rewrites multiple directives in one body with independent per-directive outcomes (G5)', function (): void {
    $index = DocumentIndex::build(contentIndexDoc());
    $body = 'See ::operation{id="forms.index"} and ::schema{name="FormData"}; but ::schema{name="Nope"} is gone.';

    [$rewritten, $diagnostics] = (new DirectiveResolver)->resolve($body, 'slug', 'slug.md', $index);

    // The two resolvable directives each gained their ref; the broken one is left literal.
    expect($rewritten)
        ->toContain('::operation{id="forms.index" ref="op:v1:formsindex00000"}')
        ->toContain('::schema{name="FormData" ref="sch:v1:formdata0000000"}')
        ->toContain('::schema{name="Nope"} is gone.');

    // Exactly one diagnostic — for the single broken directive.
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('content.unresolved-directive')
        ->and($diagnostics[0]->message)->toContain('Nope');
});

// ---- ContentResolver: nav ordering + edge cases --------------------------------------------------

it('orders nav by explicit order, then title, then slug; groups by least child order then name', function (): void {
    $content = new CompiledContent([
        makePage('b-second', ['title' => 'B', 'order' => 2, 'group' => 'Guides']),
        makePage('a-first', ['title' => 'A', 'order' => 1, 'group' => 'Guides']),
        makePage('z-ref', ['title' => 'Zebra', 'group' => 'Reference']),   // no order → last within Reference
        makePage('m-ref', ['title' => 'Middle', 'order' => 5, 'group' => 'Reference']),
    ]);

    [$extension] = (new ContentResolver)->resolve($content, contentIndexDoc());
    $nav = $extension->nav;

    // Guides (least order 1) before Reference (least order 5).
    expect(array_map(static fn ($g) => $g->title, $nav))->toBe(['Guides', 'Reference']);
    // Within Guides: order 1 then 2.
    expect(array_map(static fn ($c) => $c->title, $nav[0]->children))->toBe(['A', 'B']);
    // Within Reference: explicit order 5 (Middle) before order-less (Zebra, PHP_INT_MAX).
    expect(array_map(static fn ($c) => $c->title, $nav[1]->children))->toBe(['Middle', 'Zebra']);
});

it('excludes hidden pages from the nav but keeps them in the registry', function (): void {
    $content = new CompiledContent([
        makePage('visible', ['title' => 'Visible', 'group' => 'G']),
        makePage('secret', ['title' => 'Secret', 'group' => 'G', 'hidden' => true]),
    ]);

    [$extension] = (new ContentResolver)->resolve($content, contentIndexDoc());

    expect(array_map(static fn ($p) => $p->slug, $extension->pages))->toBe(['visible', 'secret']);
    expect(array_map(static fn ($c) => $c->title, $extension->nav[0]->children))->toBe(['Visible']);
});

it('resolves operation and tag nav nodes and diagnoses broken refs', function (): void {
    $content = new CompiledContent([
        makePage('op-ok', ['navType' => 'operation', 'navRef' => 'forms.index', 'title' => 'List']),
        makePage('tag-ok', ['navType' => 'tag', 'navRef' => 'Forms']),
        makePage('op-bad', ['navType' => 'operation', 'navRef' => 'ghost.missing']),
        makePage('tag-bad', ['navType' => 'tag', 'navRef' => 'Ghost']),
    ]);

    [$extension, $diagnostics] = (new ContentResolver)->resolve($content, contentIndexDoc());

    $refs = array_map(static fn ($n) => [$n->type, $n->ref], $extension->nav);
    expect($refs)->toContain(['operation', 'op:v1:formsindex00000'])
        ->and($refs)->toContain(['tag', 'Forms']);
    // Broken op and tag refs are dropped from the nav...
    expect(count($extension->nav))->toBe(2);
    // ...each with an error diagnostic.
    $codes = array_map(static fn ($d) => $d->code, $diagnostics);
    expect(array_filter($codes, static fn ($c) => $c === 'content.unresolved-nav-ref'))->toHaveCount(2);
});

it('diagnoses a duplicate slug and keeps only the first page', function (): void {
    $content = new CompiledContent([
        makePage('dupe', ['title' => 'First']),
        makePage('dupe', ['title' => 'Second', 'sourceFile' => 'other.md']),
    ]);

    [$extension, $diagnostics] = (new ContentResolver)->resolve($content, contentIndexDoc());

    expect($extension->pages)->toHaveCount(1)
        ->and($extension->pages[0]->title)->toBe('First');
    expect($diagnostics[0]->code)->toBe('content.duplicate-slug')
        ->and($diagnostics[0]->severity)->toBe(Severity::Error);
});

/**
 * Two pages may each ask to be the sidebar link to one operation or tag. The sidebar draws a section
 * once, so the section holds the destination once — and the one kept is the one the section's own order
 * puts first, which is a fact about the pages and not about which was compiled first.
 */
it('holds one nav entry per destination in a section, whichever order the pages arrive in', function (array $pages): void {
    $content = new CompiledContent(array_map(
        static fn (array $page): CompiledPage => makePage($page[0], [
            'title' => $page[1],
            'order' => $page[2],
            'group' => 'Reference',
            'navType' => 'operation',
            'navRef' => 'GET /api/forms',
        ]),
        $pages,
    ));

    [$extension, $diagnostics] = (new ContentResolver)->resolve($content, contentIndexDoc());

    expect($extension->nav)->toHaveCount(1)
        ->and($extension->nav[0]->type)->toBe('group')
        ->and(array_map(static fn ($n) => [$n->type, $n->ref, $n->title], $extension->nav[0]->children))
        ->toBe([['operation', 'op:v1:formsindex00000', 'Forms']]);

    // Both pages still compile — only the second link is left out.
    expect($extension->pages)->toHaveCount(2);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('content.duplicate-nav-ref')
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->message)->toContain('forms-again')
        ->and($diagnostics[0]->message)->toContain('GET /api/forms')
        ->and($diagnostics[0]->message)->toContain('"forms"');
})->with([
    'ordered first' => [[['forms', 'Forms', 1], ['forms-again', 'Forms, again', 2]]],
    'ordered last' => [[['forms-again', 'Forms, again', 2], ['forms', 'Forms', 1]]],
]);

/**
 * Per section, not per document: surfacing one endpoint under two headings is a navigation an author
 * can reasonably want, and nothing else would express it.
 */
it('keeps one destination reached from two different sections', function (): void {
    $content = new CompiledContent([
        makePage('start/forms', ['title' => 'Forms', 'group' => 'Getting started', 'navType' => 'operation', 'navRef' => 'GET /api/forms']),
        makePage('reference/forms', ['title' => 'Forms', 'group' => 'Reference', 'navType' => 'operation', 'navRef' => 'forms.index']),
    ]);

    [$extension, $diagnostics] = (new ContentResolver)->resolve($content, contentIndexDoc());

    expect(array_map(static fn ($n) => $n->title, $extension->nav))->toBe(['Getting started', 'Reference'])
        ->and($extension->nav[0]->children[0]->ref)->toBe('op:v1:formsindex00000')
        ->and($extension->nav[1]->children[0]->ref)->toBe('op:v1:formsindex00000')
        ->and($diagnostics)->toBe([]);
});

/**
 * A section sits where its least-ordered member puts it, never where its name alone would. Deduping
 * cannot move it either: the entry a section's order puts first is the one nothing is ever held back
 * at, so the least order is in the published children whatever else was dropped.
 */
it('positions a group by its least member order, not by its name', function (): void {
    $content = new CompiledContent([
        makePage('zeta/first', ['title' => 'Forms', 'order' => 9, 'group' => 'Zeta', 'navType' => 'operation', 'navRef' => 'forms.index']),
        makePage('zeta/second', ['title' => 'Forms twin', 'order' => 1, 'group' => 'Zeta', 'navType' => 'operation', 'navRef' => 'GET /api/forms']),
        makePage('alpha/page', ['title' => 'Alpha page', 'order' => 5, 'group' => 'Alpha']),
    ]);

    [$extension] = (new ContentResolver)->resolve($content, contentIndexDoc());

    // Zeta's order-1 member puts it ahead of Alpha, whose name would sort it first; the order-9 twin
    // is the entry the dedupe dropped, and it is not what either section's position was read off.
    expect(array_map(static fn ($n) => $n->title, $extension->nav))->toBe(['Zeta', 'Alpha'])
        ->and($extension->nav[0]->children)->toHaveCount(1)
        ->and($extension->nav[0]->children[0]->title)->toBe('Forms twin');
});

it('returns an empty extension with no diagnostics for empty content', function (): void {
    [$extension, $diagnostics] = (new ContentResolver)->resolve(new CompiledContent, contentIndexDoc());

    expect($extension->isEmpty())->toBeTrue()->and($diagnostics)->toBe([]);
});

// ---- CompiledContent digest ----------------------------------------------------------------------

it('digests the content tree file-order-independently and edit-sensitively', function (): void {
    $a = new CompiledContent([makePage('one', ['sourceHash' => 'h1']), makePage('two', ['sourceHash' => 'h2'])]);
    $reordered = new CompiledContent([makePage('two', ['sourceHash' => 'h2']), makePage('one', ['sourceHash' => 'h1'])]);
    $edited = new CompiledContent([makePage('one', ['sourceHash' => 'CHANGED']), makePage('two', ['sourceHash' => 'h2'])]);

    expect($a->digest())->toBe($reordered->digest())
        ->and($a->digest())->not->toBe($edited->digest())
        ->and((new CompiledContent)->digest())->toBe('');
});

it('warns on a duplicate operationId in the document index (N4)', function (): void {
    $index = DocumentIndex::build([
        'paths' => [
            '/api/a' => ['get' => ['operationId' => 'dup', 'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa']]],
            '/api/b' => ['get' => ['operationId' => 'dup', 'x-docuccino' => ['id' => 'op:v1:bbbbbbbbbbbbbbbb']]],
        ],
    ]);

    $diagnostics = $index->diagnostics();
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('content.duplicate-operation-id')
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->message)->toContain('dup');

    // Last-wins resolution is deterministic (b came last in path order).
    expect($index->resolveOperation('dup'))->toBe('op:v1:bbbbbbbbbbbbbbbb');
});

it('emits no duplicate-operationId warning for distinct ids', function (): void {
    expect(DocumentIndex::build(contentIndexDoc())->diagnostics())->toBe([]);
});
