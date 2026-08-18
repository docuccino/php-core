<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\Validator;

/**
 * Guards the packaging invariant behind item 1: the UIR schema must resolve from a real
 * (vendor/) install, not only from the monorepo checkout. Regression coverage so a
 * monorepo-relative `dirname(__DIR__, 4)` path can never ship again.
 */
it('resolves the default schema path package-relative, inside php/core/resources', function (): void {
    $corePackage = dirname(__DIR__, 2); // php/core

    expect(Validator::defaultSchemaPath())
        ->toBe($corePackage.'/resources/spec/uir/1.0/schema.json')
        ->and(is_file(Validator::defaultSchemaPath()))->toBeTrue();

    $decoded = json_decode((string) file_get_contents(Validator::defaultSchemaPath()), true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray()->toHaveKey('$id');
});

it('ships a byte-identical copy of the canonical authoring schema (drift guard)', function (): void {
    $canonical = dirname(__DIR__, 4).'/spec/uir/1.0/schema.json'; // monorepo root — authoring copy
    $shipped = dirname(__DIR__, 2).'/resources/spec/uir/1.0/schema.json';

    expect(is_file($canonical))->toBeTrue('canonical schema missing under spec/uir/1.0')
        ->and(is_file($shipped))->toBeTrue('shipped schema missing under php/core/resources — run composer sync-schema');

    // Byte equality: `composer sync-schema` copies one to the other, this proves they never drifted.
    expect(hash_file('sha256', $shipped))->toBe(hash_file('sha256', $canonical));
});

it('resolves the schema from a simulated vendor/docuccino/core install layout', function (): void {
    $tmp = sys_get_temp_dir().'/docuccino-install-shape-'.uniqid();
    $pkgRoot = $tmp.'/vendor/docuccino/core';

    // Recreate the exact shipped layout the package split produces (src/ + resources/, no monorepo root).
    @mkdir($pkgRoot.'/src/SpecValidation', 0755, true);
    @mkdir($pkgRoot.'/resources/spec/uir/1.0', 0755, true);
    copy(dirname(__DIR__, 2).'/src/SpecValidation/Validator.php', $pkgRoot.'/src/SpecValidation/Validator.php');
    copy(Validator::defaultSchemaPath(), $pkgRoot.'/resources/spec/uir/1.0/schema.json');

    // Load the RELOCATED class body in a subprocess (avoids redeclaring the already-autoloaded class)
    // and assert its self-relative resolution lands inside the temp vendor dir — proving the path is
    // anchored to the package, not to any monorepo root that a vendor install would not have.
    $script = $tmp.'/probe.php';
    file_put_contents($script, <<<PHP
        <?php
        require '{$pkgRoot}/src/SpecValidation/Validator.php';
        \$path = \\Docuccino\\Core\\SpecValidation\\Validator::defaultSchemaPath();
        echo \$path.PHP_EOL;
        echo (is_file(\$path) ? 'EXISTS' : 'MISSING').PHP_EOL;
        PHP);

    $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script));
    [$resolvedPath, $existence] = array_map('trim', explode("\n", trim($output)));

    // realpath() the package root: __DIR__ is symlink-resolved by PHP (macOS /var → /private/var).
    expect($resolvedPath)->toBe(realpath($pkgRoot).'/resources/spec/uir/1.0/schema.json')
        ->and($existence)->toBe('EXISTS');

    // cleanup
    array_map('unlink', (array) glob($pkgRoot.'/resources/spec/uir/1.0/*'));
    array_map('unlink', (array) glob($pkgRoot.'/src/SpecValidation/*'));
    unlink($script);
    foreach ([
        $pkgRoot.'/resources/spec/uir/1.0', $pkgRoot.'/resources/spec/uir', $pkgRoot.'/resources/spec',
        $pkgRoot.'/resources', $pkgRoot.'/src/SpecValidation', $pkgRoot.'/src', $pkgRoot,
        $tmp.'/vendor/docuccino', $tmp.'/vendor', $tmp,
    ] as $dir) {
        @rmdir($dir);
    }
});
