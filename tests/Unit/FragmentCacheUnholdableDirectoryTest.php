<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\OperationFragment;

/**
 * A cache directory no filesystem call can accept is a cache that is OFF.
 *
 * `file_get_contents()` and `mkdir()` raise a `ValueError` on a NUL byte and `@` does not suppress a
 * throw, so a configured directory holding one used to take the whole build down from inside the cache
 * — an error naming a PHP internal and no config key. A permanently cold cache costs one rebuild per
 * route and answers exactly what a warm one would, which is the cheapest true answer available.
 */
function unholdableFragment(): OperationFragment
{
    return new OperationFragment(
        path: '/a',
        method: 'get',
        operation: (new OperationDraft)->freeze(),
        routeSignature: 'GET /a',
    );
}

it('turns itself off for a directory no filesystem call can accept', function (): void {
    $cache = new FragmentCache(true, sys_get_temp_dir()."/docuccino-frag\0ments", 'tool', '1.0.0', 'v1');

    expect($cache->enabled())->toBeFalse();
});

it('reads and writes nothing rather than raising on one', function (): void {
    $cache = new FragmentCache(true, sys_get_temp_dir()."/docuccino-frag\0ments", 'tool', '1.0.0', 'v1');
    $key = $cache->key('GET /a', 'doc:default', 'config', []);

    // Both directions: `put()` used to raise out of mkdir() and `get()` out of file_get_contents().
    $cache->put($key, unholdableFragment(), []);

    expect($cache->get($key))->toBeNull();
});

it('still caches for a directory it can hold', function (): void {
    // The guard is about one byte, so an ordinary directory is untouched — a cache that quietly stopped
    // caching would be this fix's own failure mode.
    $directory = sys_get_temp_dir().'/docuccino-holdable-'.bin2hex(random_bytes(6));
    $cache = new FragmentCache(true, $directory, 'tool', '1.0.0', 'v1');
    $key = $cache->key('GET /a', 'doc:default', 'config', []);

    $cache->put($key, unholdableFragment(), []);
    $warm = $cache->get($key);

    array_map(unlink(...), glob($directory.'/*') ?: []);
    @rmdir($directory);

    expect($cache->enabled())->toBeTrue()
        ->and($warm?->routeSignature)->toBe('GET /a');
});
