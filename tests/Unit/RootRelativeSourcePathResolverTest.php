<?php

declare(strict_types=1);

use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;

/**
 * The resolver keeps provenance `source.file` paths portable (design §4): base-path-relative for
 * app files, composer-root-relative for files outside the base path (the workbench, path packages).
 */
it('relativises a file under the base path', function (): void {
    $corePackage = dirname(__DIR__, 2); // packages/core
    $file = $corePackage.'/src/Provenance/RootRelativeSourcePathResolver.php';

    $resolver = new RootRelativeSourcePathResolver($corePackage);

    expect($resolver->relative($file))->toBe('src/Provenance/RootRelativeSourcePathResolver.php');
});

it('falls back to the nearest composer.json root for files outside the base path', function (): void {
    $corePackage = dirname(__DIR__, 2);
    $file = $corePackage.'/src/Provenance/RootRelativeSourcePathResolver.php';

    // A base path that does not contain the file forces the composer-root walk; packages/core
    // carries its own composer.json, so the path stays portable rather than absolute.
    $resolver = new RootRelativeSourcePathResolver('/definitely/not/the/base/path');

    expect($resolver->relative($file))->toBe('src/Provenance/RootRelativeSourcePathResolver.php');
});

it('returns the path verbatim when neither the base path nor a composer root applies', function (): void {
    $file = '/'.uniqid('docuccino-no-composer-root-', true).'/nested/File.php';

    $resolver = new RootRelativeSourcePathResolver('/some/other/base');

    expect($resolver->relative($file))->toBe($file);
});
