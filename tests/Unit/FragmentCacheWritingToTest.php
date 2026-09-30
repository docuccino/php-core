<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\OperationFragment;

/*
 * Where a build's workers leave fragments when the configured store keeps none: the same keys, somewhere of
 * the build's own, and on whatever the cache it came from is.
 */

beforeEach(function (): void {
    // This test's own directory, and only it is swept: another run beside this one has one of its own.
    $this->directory = temporaryDirectory('writing-to');
});

afterEach(function (): void {
    removeTemporaryDirectory($this->directory ?? null);
});

it('writes elsewhere under the same keys, and is on whatever the cache it came from is', function (): void {
    $off = new FragmentCache(false, '', 'tool', '1.0.0', 'v1');
    $elsewhere = $off->writingTo($this->directory);

    $key = $off->key('GET /a', '', 'config', []);
    $elsewhere->put($key, new OperationFragment('/a', 'get', (new OperationDraft)->freeze(), 'GET /a'), []);

    expect($elsewhere->enabled())->toBeTrue()
        ->and($elsewhere->key('GET /a', '', 'config', []))->toBe($key)
        ->and($elsewhere->get($key))->not->toBeNull()
        ->and($off->get($key))->toBeNull()
        ->and(glob($this->directory.'/*.json'))->toHaveCount(1);
});
