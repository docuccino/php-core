<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Pipeline\FileDigests;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\OperationFragment;

/**
 * A route may depend on a file nobody has written yet — the example a `#[Example(file:)]` names, the
 * markdown a `#[DescriptionFromFile]` points at. The manifest has to be able to say "there was no such
 * file" and recognise that answer again, or every such route rebuilds on every build and the cache is
 * off for exactly the operations an author has annotated most.
 */

/**
 * A cache directory and a path inside it that no test creates unless it says so.
 *
 * @return array{string, string}
 */
function absentDependencyPaths(): array
{
    $token = bin2hex(random_bytes(6));

    return [
        sys_get_temp_dir().'/docuccino-absent-cache-'.$token,
        sys_get_temp_dir().'/docuccino-absent-dep-'.$token.'.json',
    ];
}

/** Stores one fragment against `$dependency`, then answers whether the NEXT build reads it back. */
function absentDependencyWarm(string $directory, string $dependency, Closure $between): bool
{
    $cache = static fn (): FragmentCache => new FragmentCache(true, $directory, 'tool', '1.0.0', 'v1');

    $cold = $cache();
    $key = $cold->key('GET /a', 'config', []);
    $cold->put($key, new OperationFragment('/a', 'get', (new OperationDraft)->freeze(), 'GET /a'), [$dependency]);

    $between();
    clearstatcache(true, $dependency);

    return $cache()->get($key) !== null;
}

function absentDependencyClean(string $directory, string $dependency): void
{
    @unlink($dependency);
    array_map('unlink', glob($directory.'/*') ?: []);
    @unlink($directory.'/.gitignore');
    @rmdir($directory);
}

it('reads back a fragment whose dependency was absent and still is', function (): void {
    // The bug this guards: recording "no hash" for an absent file makes absent-then never equal
    // absent-now, so pointing an attribute at a file you have not written yet turns the cache off for
    // that route — on day one, for every route that names one.
    [$directory, $dependency] = absentDependencyPaths();

    $warm = absentDependencyWarm($directory, $dependency, static function (): void {});

    absentDependencyClean($directory, $dependency);

    expect($warm)->toBeTrue();
});

it('rebuilds when a dependency that was absent has appeared', function (): void {
    [$directory, $dependency] = absentDependencyPaths();

    $warm = absentDependencyWarm($directory, $dependency, static function () use ($dependency): void {
        file_put_contents($dependency, '{"id": 1}');
    });

    absentDependencyClean($directory, $dependency);

    expect($warm)->toBeFalse();
});

it('rebuilds when a dependency that was there has gone', function (): void {
    [$directory, $dependency] = absentDependencyPaths();
    file_put_contents($dependency, '{"id": 1}');

    $warm = absentDependencyWarm($directory, $dependency, static function () use ($dependency): void {
        @unlink($dependency);
    });

    absentDependencyClean($directory, $dependency);

    expect($warm)->toBeFalse();
});

it('never reads back a fragment depending on a file it cannot read', function (): void {
    // Absent and unreadable are different facts. A file we cannot open might say anything, so an entry
    // depending on one is never fresh — the conservative half of the same distinction.
    [$directory, $dependency] = absentDependencyPaths();
    file_put_contents($dependency, '{"id": 1}');
    chmod($dependency, 0o000);
    clearstatcache(true, $dependency);

    // A process running as root reads it anyway, and there is nothing to prove there.
    $warm = @file_get_contents($dependency) === false
        && absentDependencyWarm($directory, $dependency, static function (): void {});

    chmod($dependency, 0o644);
    absentDependencyClean($directory, $dependency);

    expect($warm)->toBeFalse();
});

it('gives one build one view of whether a file is there', function (): void {
    [, $dependency] = absentDependencyPaths();

    $build = new FileDigests;
    $before = $build->exists($dependency);

    file_put_contents($dependency, '{"id": 1}');
    clearstatcache(true, $dependency);

    expect($before)->toBeFalse()
        ->and($build->exists($dependency))->toBeFalse()
        ->and((new FileDigests)->exists($dependency))->toBeTrue();

    @unlink($dependency);
});
