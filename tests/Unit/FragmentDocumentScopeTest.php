<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Pipeline\FragmentCache;

/**
 * {@see FragmentCache::documentScope()} decides whether two documents may be served each other's
 * stored fragments. Empty is the shareable answer and the document's own id is the refusal, so the
 * rows below are total over the four combinations of the two facts that force one.
 */
function documentScopeConfig(bool $recordings): DocumentConfig
{
    return new DocumentConfig(
        key: 'default',
        info: [],
        raw: $recordings ? ['examples' => ['recordings' => 'docs/recordings']] : [],
    );
}

it('shares entries only where neither the recordings nor the extension set forbids it', function (bool $recordings, bool $foreign, string $expected): void {
    $extensions = new ResolvedExtensions(
        documentTransformers: $foreign ? [applicationOwnedAnonymousTransformer()] : [],
    );

    expect(FragmentCache::documentScope(documentScopeConfig($recordings), 'doc-1', $extensions))->toBe($expected);
})->with([
    'nothing document-scoped is read' => [false, false, ''],
    'a recording is filed under this document\'s operation ids' => [true, false, 'doc-1'],
    'an extension nobody here wrote may read the document' => [false, true, 'doc-1'],
    'both at once' => [true, true, 'doc-1'],
]);

it('scopes on the identity it is handed, so two documents never collide on one scope', function (): void {
    $config = documentScopeConfig(recordings: true);
    $none = new ResolvedExtensions;

    expect(FragmentCache::documentScope($config, 'v2025-01-01', $none))->toBe('v2025-01-01')
        ->and(FragmentCache::documentScope($config, 'v2026-01-01', $none))->toBe('v2026-01-01');
});
