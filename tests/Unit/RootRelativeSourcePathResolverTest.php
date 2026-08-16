<?php

declare(strict_types=1);

use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;

/**
 * The resolver keeps provenance `source.file` paths portable (design §4): base-path-relative for
 * app files, composer-root-relative for files outside the base path (the workbench, path packages).
 */
it('relativises a file under the base path', function (): void {
    $corePackage = dirname(__DIR__, 2); // php/core
    $file = $corePackage.'/src/Provenance/RootRelativeSourcePathResolver.php';

    $resolver = new RootRelativeSourcePathResolver($corePackage);

    expect($resolver->relative($file))->toBe('src/Provenance/RootRelativeSourcePathResolver.php');
});

it('falls back to the nearest composer.json root for files outside the base path', function (): void {
    $corePackage = dirname(__DIR__, 2);
    $file = $corePackage.'/src/Provenance/RootRelativeSourcePathResolver.php';

    // A base path that does not contain the file forces the composer-root walk; php/core
    // carries its own composer.json, so the path stays portable rather than absolute.
    $resolver = new RootRelativeSourcePathResolver('/definitely/not/the/base/path');

    expect($resolver->relative($file))->toBe('src/Provenance/RootRelativeSourcePathResolver.php');
});

it('keeps the name and drops the machine path when neither the base path nor a composer root applies', function (): void {
    // An exception class from an include directory, a class loaded from outside any package. The
    // document may not carry an absolute path — it is the one thing in a provenance record that differs
    // between two machines building the same code — so the file degrades to what is still true of it.
    $directory = '/'.uniqid('docuccino-no-composer-root-', true).'/nested';

    $resolver = new RootRelativeSourcePathResolver('/some/other/base');

    expect($resolver->relative($directory.'/File.php'))->toBe('File.php')
        ->and($resolver->relative($directory.'/File.php'))->not->toContain($directory);
});

it('emits no leading slash for any path it is given', function (string $case, string $base, string $file): void {
    // The invariant behind the rows above, as one statement: whatever comes in, what goes out is a
    // relative path, so nothing downstream has to know which of the three routes answered.
    expect((new RootRelativeSourcePathResolver($base))->relative($file))->not->toStartWith('/');
})->with(function (): array {
    $corePackage = dirname(__DIR__, 2);

    return [
        ['under the base path', $corePackage, $corePackage.'/src/Provenance/RootRelativeSourcePathResolver.php'],
        ['under a composer root', '/not/the/base', $corePackage.'/src/Provenance/RootRelativeSourcePathResolver.php'],
        ['under neither', '/not/the/base', '/'.uniqid('docuccino-nowhere-', true).'/Vendor/Thing.php'],
        ['at the filesystem root', '/not/the/base', '/'.uniqid('docuccino-root-file-', true).'.php'],
    ];
});
