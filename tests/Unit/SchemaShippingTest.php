<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Core\SpecValidation\Validator;

/**
 * Guards the packaging invariant behind item 1: the UIR schema must resolve from a real
 * (vendor/) install, not only from the monorepo checkout. Regression coverage so a
 * monorepo-relative `dirname(__DIR__, 4)` path can never ship again.
 */
it('resolves the default schema paths package-relative, inside php/core/resources', function (): void {
    $corePackage = dirname(__DIR__, 2); // php/core

    expect(Validator::defaultSchemaPath())
        ->toBe($corePackage.'/resources/spec/uir/'.defaultSchemaVersion().'/schema.json')
        ->and(is_file(Validator::defaultSchemaPath()))->toBeTrue()
        // The extension schema the document schema references resolves the same way, or a validation
        // run reaches for spec.docuccino.app over the network.
        ->and(Validator::defaultExtensionSchemaPath())
        ->toBe($corePackage.'/resources/spec/uir/'.defaultSchemaVersion().'/extension.schema.json')
        ->and(is_file(Validator::defaultExtensionSchemaPath()))->toBeTrue();

    foreach ([Validator::defaultSchemaPath(), Validator::defaultExtensionSchemaPath()] as $path) {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBeArray()->toHaveKey('$id');
    }
});

/**
 * The three roots one schema family lives at: the authoring copy, the copy php/core ships inside the
 * composer package, and the copy the docs site serves at each `$id` URL. Named here because the drift
 * guard below is the only thing that holds all three together — `website/scripts/sync-schema.mjs
 * --check` covers the third, but it runs where the SITE is built, and a guard a standalone deploy
 * skips is the reason `SpecSiteCoverageTest` lives in this suite too.
 *
 * @return array<string, string>
 */
function schemaCopyRoots(): array
{
    return [
        'php/core/resources' => dirname(__DIR__, 2).'/resources/spec/uir',
        'website/public' => dirname(__DIR__, 4).'/website/public/uir',
    ];
}

/**
 * {@see schemaCopyRoots()} as dataset rows, so a failure names the root rather than a number.
 *
 * @return array<string, array{string, string}>
 */
function schemaCopyRootRows(): array
{
    $rows = [];
    foreach (schemaCopyRoots() as $label => $root) {
        $rows[$label] = [$label, $root];
    }

    return $rows;
}

it('publishes at least the versions the schema history has reached', function (): void {
    // A scan that stopped seeing the directory would pass every drift check below on an empty set.
    expect(publishedSchemaVersions())->toContain('1.0')->toContain('1.1')->toContain('2.0');
});

it('reads a plausible minimum of published schema files', function (): void {
    // A scan over an empty directory would report every copy in sync.
    expect(count(publishedSchemaFiles()))->toBeGreaterThanOrEqual(4)
        ->and(array_keys(publishedSchemaFiles()))->toContain('2.0/extension.schema.json');
});

it('ships a byte-identical copy of every canonical authoring schema (drift guard)', function (string $version, string $name): void {
    $canonical = dirname(__DIR__, 4).'/spec/uir/'.$version.'/'.$name; // monorepo root — authoring copy

    expect(is_file($canonical))->toBeTrue('canonical schema missing under spec/uir/'.$version);

    foreach (schemaCopyRoots() as $label => $root) {
        $copy = $root.'/'.$version.'/'.$name;

        expect(is_file($copy))->toBeTrue($label.' is missing '.$version.'/'.$name.' — run composer sync-schema')
            // Byte equality: the sync tools copy one to the others, this proves they never drifted.
            ->and(hash_file('sha256', $copy))->toBe(hash_file('sha256', $canonical), $label.' has drifted from spec/uir/'.$version.'/'.$name);
    }
})->with(publishedSchemaFiles());

/*
 * And the other direction, which a per-file lookup cannot see. A copy root holding a file `spec/`
 * no longer authors keeps serving — and SHIPPING, inside the composer package — a schema nobody
 * maintains, with every check above green because each one only ever asks whether a canonical file
 * arrived. The set the copy holds has to EQUAL the set spec/ publishes, and hold nothing else at all:
 * `.json` is what a schema file is, so anything that is not one is a stray that got carried along.
 */
it('holds each copy root to exactly the set of files spec/ publishes, and nothing besides', function (string $label, string $root): void {
    expect(array_keys(schemaFilesUnder($root)))->toBe(array_keys(publishedSchemaFiles()), $label.' — run composer sync-schema')
        ->and(everyFileUnder($root))->toBe(array_keys(publishedSchemaFiles()), $label.' carries a file that is not a published schema');
})->with(schemaCopyRootRows());

it('calls a copy root short, and calls a stray in one a stray', function (): void {
    // Both halves of the equality executed against directories written to fail them, because "every
    // canonical file arrived" is exactly what was true of a copy root carrying an extra file.
    $tmp = sys_get_temp_dir().'/docuccino-copy-root-'.uniqid();

    foreach (array_keys(publishedSchemaFiles()) as $name) {
        @mkdir($tmp.'/'.dirname($name), 0755, true);
        copy(dirname(__DIR__, 4).'/spec/uir/'.$name, $tmp.'/'.$name);
    }

    // Positive control: a faithful copy satisfies both halves.
    expect(array_keys(schemaFilesUnder($tmp)))->toBe(array_keys(publishedSchemaFiles()))
        ->and(everyFileUnder($tmp))->toBe(array_keys(publishedSchemaFiles()));

    file_put_contents($tmp.'/2.0/.DS_Store', 'stray');

    expect(array_keys(schemaFilesUnder($tmp)))->toBe(array_keys(publishedSchemaFiles()))
        // The stray is invisible to the `.json` read and caught by the one beside it, which is the
        // whole reason there are two.
        ->and(everyFileUnder($tmp))->toContain('2.0/.DS_Store');

    unlink($tmp.'/2.0/.DS_Store');
    unlink($tmp.'/2.0/extension.schema.json');

    expect(array_keys(schemaFilesUnder($tmp)))->not->toBe(array_keys(publishedSchemaFiles()));

    foreach (array_keys(publishedSchemaFiles()) as $name) {
        @unlink($tmp.'/'.$name);
    }
    foreach (publishedSchemaVersions() as $version) {
        @rmdir($tmp.'/'.$version);
    }
    @rmdir($tmp);
});

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
    copy(Validator::defaultExtensionSchemaPath(), $pkgRoot.'/resources/spec/uir/'.defaultSchemaVersion().'/extension.schema.json');

    // Load the RELOCATED class body in a subprocess (avoids redeclaring the already-autoloaded class)
    // and assert its self-relative resolution lands inside the temp vendor dir — proving the path is
    // anchored to the package, not to any monorepo root that a vendor install would not have.
    $script = $tmp.'/probe.php';
    file_put_contents($script, <<<PHP
        <?php
        require '{$pkgRoot}/src/Spec/UirSpec.php';
        require '{$pkgRoot}/src/SpecValidation/Validator.php';
        \$path = \\Docuccino\\Core\\SpecValidation\\Validator::defaultSchemaPath();
        \$extension = \\Docuccino\\Core\\SpecValidation\\Validator::defaultExtensionSchemaPath();
        echo \$path.PHP_EOL;
        echo (is_file(\$path) && is_file(\$extension) ? 'EXISTS' : 'MISSING').PHP_EOL;
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
