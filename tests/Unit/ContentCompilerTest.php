<?php

declare(strict_types=1);

use Docuccino\Core\Content\ContentCompiler;
use Docuccino\Core\Content\Frontmatter;
use Docuccino\Core\Extensions\Context\DocumentConfig;

/**
 * The adapter's filesystem/frontmatter half of the content pipeline: the frontmatter splitter and
 * the markdown-tree compiler (path-derived slugs/groups, frontmatter overrides, determinism, and
 * the missing/escaping/empty-directory branches).
 */

// ---- Frontmatter ---------------------------------------------------------------------------------

it('splits frontmatter from body', function (string $raw, array $expectedMeta, string $expectedBody): void {
    [$meta, $body] = Frontmatter::parse($raw);

    expect($meta)->toBe($expectedMeta)->and($body)->toBe($expectedBody);
})->with([
    'fenced metadata + body' => [
        "---\ntitle: Intro\nnav:\n  order: 2\n---\nHello body.\n",
        ['title' => 'Intro', 'nav' => ['order' => 2]],
        "Hello body.\n",
    ],
    'no fence is all body' => [
        "Just a body.\n",
        [],
        "Just a body.\n",
    ],
    'malformed yaml degrades to empty metadata' => [
        "---\n: : : not: valid\n---\nBody.\n",
        [],
        "Body.\n",
    ],
    'unterminated fence is treated as body' => [
        "---\ntitle: Intro\nstill going",
        [],
        "---\ntitle: Intro\nstill going",
    ],
    'crlf is normalised before splitting' => [
        "---\r\ntitle: Intro\r\n---\r\nBody.\r\n",
        ['title' => 'Intro'],
        "Body.\n",
    ],
]);

// ---- ContentCompiler -----------------------------------------------------------------------------

function compilerDir(): string
{
    $dir = sys_get_temp_dir().'/docuccino-compiler-'.uniqid();
    mkdir($dir.'/getting-started', 0777, true);

    return $dir;
}

function configForDir(string $dir): DocumentConfig
{
    return new DocumentConfig(key: 'default', info: [], raw: ['content' => ['dir' => $dir]]);
}

it('compiles a markdown tree with path-derived slugs, groups and frontmatter overrides', function (): void {
    $dir = compilerDir();
    file_put_contents($dir.'/getting-started/intro.md', "---\ntitle: Getting Started\nsummary: Start here.\ntags: [a, b]\nnav:\n  order: 1\n---\nBody one.\n");
    file_put_contents($dir.'/root-page.md', "Body two.\n");

    [$content, $diagnostics] = (new ContentCompiler(sys_get_temp_dir()))->compile(configForDir($dir));

    expect($diagnostics)->toBe([]);
    // Sorted by absolute path: getting-started/intro before root-page.
    [$intro, $root] = $content->pages;

    expect($intro->slug)->toBe('getting-started/intro')
        ->and($intro->title)->toBe('Getting Started')
        ->and($intro->summary)->toBe('Start here.')
        ->and($intro->tags)->toBe(['a', 'b'])
        ->and($intro->order)->toBe(1)
        ->and($intro->group)->toBe('Getting Started') // folder-derived, humanised
        ->and($intro->body)->toBe('Body one.');

    // A root file: humanised title from the filename, no group.
    expect($root->slug)->toBe('root-page')
        ->and($root->title)->toBe('Root Page')
        ->and($root->group)->toBeNull();

    array_map('unlink', [$dir.'/getting-started/intro.md', $dir.'/root-page.md']);
    rmdir($dir.'/getting-started');
    rmdir($dir);
});

it('clamps nav.type to page for unknown values (mapping-table degradation)', function (string $type, string $expected): void {
    $dir = compilerDir();
    file_put_contents($dir.'/getting-started/nav.md', "---\nnav:\n  type: $type\n---\n");

    [$content] = (new ContentCompiler(sys_get_temp_dir()))->compile(configForDir($dir));

    expect($content->pages[0]->navType)->toBe($expected);

    unlink($dir.'/getting-started/nav.md');
    rmdir($dir.'/getting-started');
    rmdir($dir);
})->with([
    'page passes through' => ['page', 'page'],
    'operation passes through' => ['operation', 'operation'],
    'tag passes through' => ['tag', 'tag'],
    'unknown clamps to page' => ['bogus', 'page'],
]);

it('degrades ill-typed nav.order and tags frontmatter (order → null, scalar tags → [])', function (): void {
    $dir = compilerDir();
    // order is a string (not an int) and tags is a scalar (not a list): both must degrade cleanly.
    file_put_contents($dir.'/getting-started/bad.md', "---\nnav:\n  order: not-an-int\ntags: single\n---\n");

    [$content] = (new ContentCompiler(sys_get_temp_dir()))->compile(configForDir($dir));
    $page = $content->pages[0];

    expect($page->order)->toBeNull()
        ->and($page->tags)->toBe([]);

    unlink($dir.'/getting-started/bad.md');
    rmdir($dir.'/getting-started');
    rmdir($dir);
});

it('reads nav type/ref/hidden overrides and a frontmatter slug/group', function (): void {
    $dir = compilerDir();
    file_put_contents($dir.'/getting-started/op.md', "---\nslug: custom/slug\nnav:\n  type: operation\n  ref: GET /api/forms\n  hidden: true\n  group: Custom Group\n---\n");

    [$content] = (new ContentCompiler(sys_get_temp_dir()))->compile(configForDir($dir));
    $page = $content->pages[0];

    expect($page->slug)->toBe('custom/slug')
        ->and($page->navType)->toBe('operation')
        ->and($page->navRef)->toBe('GET /api/forms')
        ->and($page->hidden)->toBeTrue()
        ->and($page->group)->toBe('Custom Group');

    unlink($dir.'/getting-started/op.md');
    rmdir($dir.'/getting-started');
    rmdir($dir);
});

it('makes source paths project-root-relative when the dir is under the base path', function (): void {
    $base = sys_get_temp_dir().'/docuccino-base-'.uniqid();
    mkdir($base.'/docs/api', 0777, true);
    file_put_contents($base.'/docs/api/intro.md', "Body.\n");

    [$content] = (new ContentCompiler($base))->compile(configForDir($base.'/docs/api'));

    expect($content->pages[0]->sourceFile)->toBe('docs/api/intro.md');

    unlink($base.'/docs/api/intro.md');
    rmdir($base.'/docs/api');
    rmdir($base.'/docs');
    rmdir($base);
});

it('is deterministic regardless of filesystem read order (sorted)', function (): void {
    $dir = compilerDir();
    file_put_contents($dir.'/zzz.md', "Z.\n");
    file_put_contents($dir.'/aaa.md', "A.\n");
    file_put_contents($dir.'/getting-started/mmm.md', "M.\n");

    [$content] = (new ContentCompiler(sys_get_temp_dir()))->compile(configForDir($dir));

    expect(array_map(static fn ($p) => $p->slug, $content->pages))
        ->toBe(['aaa', 'getting-started/mmm', 'zzz']);

    array_map('unlink', [$dir.'/zzz.md', $dir.'/aaa.md', $dir.'/getting-started/mmm.md']);
    rmdir($dir.'/getting-started');
    rmdir($dir);
});

it('compiles nothing (no diagnostic) when content.dir is unset', function (): void {
    $config = new DocumentConfig(key: 'default', info: [], raw: []);

    [$content, $diagnostics] = (new ContentCompiler(sys_get_temp_dir()))->compile($config);

    expect($content->isEmpty())->toBeTrue()->and($diagnostics)->toBe([]);
});

it('warns when the configured directory is missing', function (): void {
    [$content, $diagnostics] = (new ContentCompiler(sys_get_temp_dir()))->compile(configForDir('/no/such/dir/'.uniqid()));

    expect($content->isEmpty())->toBeTrue()
        ->and($diagnostics[0]->code)->toBe('content.dir-missing');
});

it('warns and reads nothing when a relative content.dir escapes the base path', function (): void {
    $config = new DocumentConfig(key: 'default', info: [], raw: ['content' => ['dir' => '../../../etc']]);

    [$content, $diagnostics] = (new ContentCompiler(sys_get_temp_dir().'/docuccino-base'))->compile($config);

    expect($content->isEmpty())->toBeTrue()
        ->and($diagnostics[0]->code)->toBe('content.dir-escapes-base');
});
