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

it('does not read a sibling directory sharing a prefix as inside the base path', function (): void {
    $base = '/'.uniqid('docuccino-base-', true);

    $resolver = new RootRelativeSourcePathResolver($base);

    // Stripping the base off a path that merely starts with its characters would publish
    // `-extra/src/Thing.php`, which points at nothing. The file is outside the base path like any other.
    expect($resolver->relative($base.'-extra/src/Thing.php'))->toBe('Thing.php');
});

it('leaves an already-relative path as it found it', function (): void {
    // Nothing to strip and nothing to walk up from: the path is already the portable form the resolver
    // exists to produce, and taking its basename would throw away directories that were fine.
    $resolver = new RootRelativeSourcePathResolver('/some/base');

    expect($resolver->relative('modules/Form/FormController.php'))->toBe('modules/Form/FormController.php');
});

it('recognises a Windows drive letter as absolute rather than as a relative path', function (): void {
    // Backslashes are normalised first, so what is left is `C:/…` — still a machine path, and still
    // something the document may not carry.
    $resolver = new RootRelativeSourcePathResolver('/some/base');

    expect($resolver->relative('C:\\'.uniqid('docuccino-windows-', true).'\\app\\Thing.php'))->toBe('Thing.php');
});

it('publishes no machine path for a file reached through a symlinked prefix', function (): void {
    // The base path and reflection can disagree about the same directory — `/tmp` is a symlink to
    // `/private/tmp`, a checkout can live under one — so the prefix compare finds nothing to strip. The
    // composer walk follows the link and answers instead, which is the point of having a second rung.
    $real = sys_get_temp_dir().'/'.uniqid('docuccino-symlink-real-', true);
    $link = sys_get_temp_dir().'/'.uniqid('docuccino-symlink-', true);
    mkdir($real.'/package/src', 0777, true);
    file_put_contents($real.'/package/composer.json', '{}');
    symlink($real, $link);

    $resolver = new RootRelativeSourcePathResolver($real);

    expect($resolver->relative($link.'/package/src/Thing.php'))->toBe('src/Thing.php');

    unlink($link);
    unlink($real.'/package/composer.json');
    rmdir($real.'/package/src');
    rmdir($real.'/package');
    rmdir($real);
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
