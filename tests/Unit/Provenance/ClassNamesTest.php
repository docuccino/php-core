<?php

declare(strict_types=1);

use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;

/*
 * A diagnostic is embedded in the document, and an anonymous class's `::class` hides a machine path
 * inside it — `{base}@anonymous`, a NUL byte, `{absolute file}:{line}${n}`. Every published name for
 * one goes through here, so the two things that cannot be published (the absolute path, and a counter
 * two runs need not agree on) are stripped in exactly one place.
 */

function repositoryRoot(): string
{
    return dirname(__DIR__, 5);
}

it('leaves a named class exactly as written', function (): void {
    $names = new ClassNames(new RootRelativeSourcePathResolver('/nowhere'));

    expect($names->of(new RuntimeException))->toBe(RuntimeException::class);
});

it('relativises the file an anonymous class names, and drops the per-process counter', function (): void {
    $names = new ClassNames(new RootRelativeSourcePathResolver(repositoryRoot()));

    $line = __LINE__ + 1;
    $name = $names->of(new class extends RuntimeException {});

    expect($name)->not->toContain("\0")
        ->and($name)->not->toContain(repositoryRoot())
        ->and($name)->toStartWith('RuntimeException@anonymous declared in ')
        // The base path is the repository root, so the file relativises against it whole.
        ->and($name)->toBe(
            'RuntimeException@anonymous declared in php/core/tests/Unit/Provenance/ClassNamesTest.php:'.$line,
        );
});

it('falls back to the package root when the file sits outside the base path', function (): void {
    // The degradation RootRelativeSourcePathResolver documents: no base to strip, so the nearest
    // composer.json ancestor becomes the root. Here that is php/core's own.
    $names = new ClassNames(new RootRelativeSourcePathResolver('/nowhere'));

    $line = __LINE__ + 1;
    $name = $names->of(new class extends RuntimeException {});

    expect($name)->toBe(
        'RuntimeException@anonymous declared in tests/Unit/Provenance/ClassNamesTest.php:'.$line,
    );
});
