<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Pipeline\FileDigests;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\OperationFragment;

/**
 * The dependency-hash memo behind fragment freshness: one build hashes a file once and sees one view
 * of it, and the memo dies with the cache that owns it — a long-lived process (queue worker, or the
 * viewer generating per request) must never serve a hash a previous build read.
 */
it('hashes a file once per build and re-reads it in the next one', function (): void {
    $file = sys_get_temp_dir().'/docuccino-digests-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($file, '<?php // v1');

    $build = new FileDigests;
    $first = $build->of($file);

    file_put_contents($file, '<?php // v2');
    clearstatcache(true, $file);

    expect($first)->toBe(hash('sha256', '<?php // v1'))
        // One build, one view of the file …
        ->and($build->of($file))->toBe($first)
        // … and the next build reads it afresh.
        ->and((new FileDigests)->of($file))->toBe(hash('sha256', '<?php // v2'));

    @unlink($file);
});

it('memoises an unreadable file as unreadable rather than as an empty hash', function (): void {
    $digests = new FileDigests;
    $missing = sys_get_temp_dir().'/docuccino-digests-missing-'.bin2hex(random_bytes(6)).'.php';

    expect($digests->of($missing))->toBeFalse()
        ->and($digests->of($missing))->toBeFalse();
});

it('gives one build one view of a dependency file, and the next build a fresh one', function (): void {
    $directory = sys_get_temp_dir().'/docuccino-digests-cache-'.bin2hex(random_bytes(6));
    $dependency = sys_get_temp_dir().'/docuccino-digests-dep-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($dependency, '<?php // v1');

    $build = new FragmentCache(true, $directory, 'tool', '1.0.0', 'v1');
    $key = $build->key('GET /a', 'config', []);
    $build->put($key, new OperationFragment('/a', 'get', (new OperationDraft)->freeze(), 'GET /a'), [$dependency]);

    file_put_contents($dependency, '<?php // v2');
    clearstatcache(true, $dependency);

    // The build that wrote the entry keeps the view it hashed; a later build sees the edit and misses.
    expect($build->get($key))->not->toBeNull()
        ->and((new FragmentCache(true, $directory, 'tool', '1.0.0', 'v1'))->get($key))->toBeNull();

    @unlink($dependency);
    array_map('unlink', glob($directory.'/*') ?: []);
    @unlink($directory.'/.gitignore');
    @rmdir($directory);
});
