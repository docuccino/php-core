<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ExportTarget;

/**
 * How a document reads its export targets, and everything it reports as wrong with them. Malformed
 * entries are dropped rather than guessed at, and every drop is reported — the adapter refuses to
 * build on any of them, so a half-read target list can never reach a write.
 */
function configWithExport(mixed $export): DocumentConfig
{
    return new DocumentConfig(key: 'default', info: [], raw: ['export' => $export]);
}

/**
 * @return list<array{string, string}>
 */
function targetPairs(DocumentConfig $config): array
{
    return array_map(
        static fn (ExportTarget $t): array => [$t->format, $t->path],
        $config->exportTargets(),
    );
}

it('falls back to one default target when nothing is configured', function (): void {
    $config = new DocumentConfig(key: 'default', info: []);

    expect(targetPairs($config))->toBe([['openapi-3.2', 'docs/openapi.json']])
        ->and($config->exportTargetIssues())->toBe([]);
});

it('reads the one-target export.path shorthand', function (): void {
    $config = configWithExport(['path' => 'build/api.json']);

    expect(targetPairs($config))->toBe([['openapi-3.2', 'build/api.json']])
        ->and($config->exportTargetIssues())->toBe([]);
});

it('reads a list of targets', function (): void {
    $config = configWithExport(['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
        ['format' => 'openapi-3.1', 'path' => 'docs/openapi-3.1.yaml'],
        ['format' => 'uir', 'path' => 'docs/api.uir.json'],
    ]]);

    expect(targetPairs($config))->toBe([
        ['openapi-3.2', 'docs/openapi.json'],
        ['openapi-3.1', 'docs/openapi-3.1.yaml'],
        ['uir', 'docs/api.uir.json'],
    ])->and($config->exportTargetIssues())->toBe([]);
});

it('lets a target list supersede export.path, and says the path is now dead config', function (): void {
    $config = configWithExport([
        'path' => 'docs/openapi.json',
        'targets' => [['format' => 'uir', 'path' => 'docs/api.uir.json']],
    ]);

    // The shipped config ships a `path`, so adding targets next to it is the expected upgrade move —
    // it must work, and it must say the leftover key writes nothing.
    expect(targetPairs($config))->toBe([['uir', 'docs/api.uir.json']]);

    $issues = $config->exportTargetIssues();
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['problem'])->toBe('path-ignored')
        ->and($issues[0]['detail'])->toBe('docs/openapi.json');
});

it('reads YAML off the path extension rather than a flag', function (string $path, bool $yaml): void {
    expect((new ExportTarget('openapi-3.2', $path))->yaml())->toBe($yaml);
})->with([
    'json' => ['docs/openapi.json', false],
    'yaml' => ['docs/openapi.yaml', true],
    'yml' => ['docs/openapi.yml', true],
    'uppercase' => ['docs/openapi.YAML', true],
    'no extension' => ['docs/openapi', false],
    'yaml in a directory name' => ['yaml/openapi.json', false],
]);

it('reports every way a target list can be wrong', function (mixed $export, string $problem, string $detail): void {
    $issues = configWithExport($export)->exportTargetIssues();

    expect($issues)->not->toBeEmpty()
        ->and(array_column($issues, 'problem'))->toContain($problem);

    $matching = array_values(array_filter($issues, static fn (array $i): bool => $i['problem'] === $problem));
    expect($matching[0]['detail'])->toBe($detail);
})->with([
    'empty list' => [['targets' => []], 'empty', ''],
    'not a list' => [['targets' => ['a' => ['format' => 'uir', 'path' => 'x.json']]], 'empty', ''],
    'entry is a scalar' => [['targets' => ['nope']], 'shape', 'string'],
    'missing format' => [['targets' => [['path' => 'x.json']]], 'shape', ''],
    'missing path' => [['targets' => [['format' => 'uir']]], 'shape', ''],
    'blank format' => [['targets' => [['format' => '', 'path' => 'x.json']]], 'shape', ''],
    'unknown format' => [['targets' => [['format' => 'swagger-2.0', 'path' => 'x.json']]], 'unknown-format', 'swagger-2.0'],
    'yaml on a json-only format' => [['targets' => [['format' => 'uir', 'path' => 'x.yaml']]], 'yaml-unsupported', 'uir => x.yaml'],
    'two targets one path' => [['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'x.json'],
        ['format' => 'openapi-3.1', 'path' => 'x.json'],
    ]], 'duplicate-path', 'x.json'],
    'two targets one format' => [['targets' => [
        ['format' => 'uir', 'path' => 'a.json'],
        ['format' => 'uir', 'path' => 'b.json'],
    ]], 'duplicate-format', 'uir'],
]);

it('drops a malformed entry from the targets it reads, keeping the usable ones', function (): void {
    $config = configWithExport(['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
        'not-a-target',
        ['format' => 'uir', 'path' => 'docs/api.uir.json'],
    ]]);

    expect(targetPairs($config))->toBe([
        ['openapi-3.2', 'docs/openapi.json'],
        ['uir', 'docs/api.uir.json'],
    ])->and(array_column($config->exportTargetIssues(), 'problem'))->toContain('shape');
});

it('reports a malformed list rather than silently falling back to the default target', function (): void {
    // Falling back here would write `docs/openapi.json` from a config that asked for something else.
    $config = configWithExport(['targets' => ['nope']]);

    expect($config->exportTargetIssues())->not->toBeEmpty()
        ->and(targetPairs($config))->toBe([['openapi-3.2', 'docs/openapi.json']]);
});

it('reports the index of the offending entry', function (): void {
    $issues = configWithExport(['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'a.json'],
        ['format' => 'nope', 'path' => 'b.json'],
    ]])->exportTargetIssues();

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['index'])->toBe(1);
});

it('ignores a non-array export bag', function (mixed $export): void {
    $config = configWithExport($export);

    expect(targetPairs($config))->toBe([['openapi-3.2', 'docs/openapi.json']])
        ->and($config->exportTargetIssues())->toBe([]);
})->with([
    'string' => ['docs/openapi.json'],
    'null' => [null],
    'int' => [7],
]);
