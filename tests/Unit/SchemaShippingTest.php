<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Core\SpecValidation\Validator;

/**
 * Guards the packaging invariant behind item 1: the UIR schema must resolve from a real
 * (vendor/) install, not only from the monorepo checkout. Regression coverage so a
 * monorepo-relative `dirname(__DIR__, 4)` path can never ship again.
 */
it('resolves the default schema path package-relative, inside php/core/resources', function (): void {
    $corePackage = dirname(__DIR__, 2); // php/core

    expect(Validator::defaultSchemaPath())
        ->toBe($corePackage.'/resources/spec/uir/'.defaultSchemaVersion().'/schema.json')
        ->and(is_file(Validator::defaultSchemaPath()))->toBeTrue();

    $decoded = json_decode((string) file_get_contents(Validator::defaultSchemaPath()), true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray()->toHaveKey('$id');
});

/**
 * Every UIR version the repository publishes, read off the authoring directory rather than listed.
 * A published `$id` is served forever, so the guard below has to cover the versions that are no
 * longer newest — listing them by hand is how the second one goes unguarded the day a third ships.
 *
 * @return list<string>
 */
function publishedSchemaVersions(): array
{
    $root = dirname(__DIR__, 4).'/spec/uir';

    $versions = array_values(array_filter(
        scandir($root) ?: [],
        static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($root.'/'.$entry),
    ));
    sort($versions);

    return $versions;
}

/** The version a fresh build validates against: the newest the repository publishes. */
function defaultSchemaVersion(): string
{
    $versions = publishedSchemaVersions();

    return $versions[count($versions) - 1];
}

it('publishes at least the versions the schema history has reached', function (): void {
    // A scan that stopped seeing the directory would pass every drift check below on an empty set.
    expect(publishedSchemaVersions())->toContain('1.0')->toContain('1.1');
});

it('ships a byte-identical copy of every canonical authoring schema (drift guard)', function (string $version): void {
    $canonical = dirname(__DIR__, 4).'/spec/uir/'.$version.'/schema.json'; // monorepo root — authoring copy
    $shipped = dirname(__DIR__, 2).'/resources/spec/uir/'.$version.'/schema.json';

    expect(is_file($canonical))->toBeTrue('canonical schema missing under spec/uir/'.$version)
        ->and(is_file($shipped))->toBeTrue('shipped schema missing under php/core/resources — run composer sync-schema');

    // Byte equality: `composer sync-schema` copies one to the other, this proves they never drifted.
    expect(hash_file('sha256', $shipped))->toBe(hash_file('sha256', $canonical));
})->with(publishedSchemaVersions());

it('resolves the schema from a simulated vendor/docuccino/core install layout', function (): void {
    $tmp = sys_get_temp_dir().'/docuccino-install-shape-'.uniqid();
    $pkgRoot = $tmp.'/vendor/docuccino/core';

    // Recreate the exact shipped layout the package split produces (src/ + resources/, no monorepo root).
    @mkdir($pkgRoot.'/src/SpecValidation', 0755, true);
    @mkdir($pkgRoot.'/resources/spec/uir/'.defaultSchemaVersion(), 0755, true);
    copy(dirname(__DIR__, 2).'/src/SpecValidation/Validator.php', $pkgRoot.'/src/SpecValidation/Validator.php');

    // The version it resolves the path from ships beside it, so the probe below needs it too — a
    // package-relative path is only package-relative if everything it reads is in the package.
    @mkdir($pkgRoot.'/src/Spec', 0755, true);
    copy(dirname(__DIR__, 2).'/src/Spec/UirSpec.php', $pkgRoot.'/src/Spec/UirSpec.php');
    copy(Validator::defaultSchemaPath(), $pkgRoot.'/resources/spec/uir/'.defaultSchemaVersion().'/schema.json');

    // Load the RELOCATED class body in a subprocess (avoids redeclaring the already-autoloaded class)
    // and assert its self-relative resolution lands inside the temp vendor dir — proving the path is
    // anchored to the package, not to any monorepo root that a vendor install would not have.
    $script = $tmp.'/probe.php';
    file_put_contents($script, <<<PHP
        <?php
        require '{$pkgRoot}/src/Spec/UirSpec.php';
        require '{$pkgRoot}/src/SpecValidation/Validator.php';
        \$path = \\Docuccino\\Core\\SpecValidation\\Validator::defaultSchemaPath();
        echo \$path.PHP_EOL;
        echo (is_file(\$path) ? 'EXISTS' : 'MISSING').PHP_EOL;
        PHP);

    $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script));
    [$resolvedPath, $existence] = array_map('trim', explode("\n", trim($output)));

    // realpath() the package root: __DIR__ is symlink-resolved by PHP (macOS /var → /private/var).
    expect($resolvedPath)->toBe(realpath($pkgRoot).'/resources/spec/uir/'.defaultSchemaVersion().'/schema.json')
        ->and($existence)->toBe('EXISTS');

    // cleanup
    array_map('unlink', (array) glob($pkgRoot.'/resources/spec/uir/'.defaultSchemaVersion().'/*'));
    array_map('unlink', (array) glob($pkgRoot.'/src/SpecValidation/*'));
    unlink($script);
    foreach ([
        $pkgRoot.'/resources/spec/uir/'.defaultSchemaVersion(), $pkgRoot.'/resources/spec/uir', $pkgRoot.'/resources/spec',
        $pkgRoot.'/resources', $pkgRoot.'/src/SpecValidation', $pkgRoot.'/src', $pkgRoot,
        $tmp.'/vendor/docuccino', $tmp.'/vendor', $tmp,
    ] as $dir) {
        @rmdir($dir);
    }
});

/**
 * The same packaging invariant for the OpenAPI meta-schemas, which now ship for the same reason the
 * UIR schema does: the emitters validate what they emit against them on every build, so a
 * `vendor/docuccino/core` install that resolved them out of `tests/` would have no validation at all —
 * and `tests/` is `export-ignore`d, so it would have no files either.
 */
it('ships every vendored OpenAPI meta-schema inside php/core/resources', function (): void {
    $corePackage = dirname(__DIR__, 2); // php/core

    expect(OpenApiMetaSchema::SCHEMAS)->toHaveCount(3);

    foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
        $path = OpenApiMetaSchema::path($format);

        expect($path)->toStartWith($corePackage.'/resources/openapi/')
            ->and(is_file($path))->toBeTrue($format);
    }

    // `/tests` is export-ignored and `/resources` is not, which is the whole of why they moved.
    $attributes = (string) file_get_contents($corePackage.'/.gitattributes');

    expect($attributes)->toContain('/tests           export-ignore')
        ->and($attributes)->not->toContain('/resources');
});
