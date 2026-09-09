<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;

/**
 * The shape a representative configuration parses to, pinned twice over.
 *
 * The GOLDEN holds the whole resolved parse as bytes, so a re-typing anywhere at any depth shows up as
 * a diff — including one arriving from a `symfony/yaml` minor nobody read the changelog of. It is the
 * broad net: it notices everything and says nothing about why.
 *
 * The TABLE names the type of every leaf by hand, so a move says which setting changed and to what.
 * It is held to the parse in BOTH directions: a leaf the table does not name fails, and a name the
 * parse no longer produces fails too. One direction alone is a guard that stops guarding — a table
 * short by a row would otherwise pass forever, which is how an attribute once shipped uncatalogued.
 *
 * Neither is derived from the other, and neither is derived from the reader's own opinion of what it
 * produced: the table is typed out, and the walk that checks it reads the parsed values.
 */
$fixture = dirname(__DIR__).'/Fixtures/config/representative.yaml';

/**
 * Every leaf of the representative parse, and the PHP type it must have. A container is not a leaf; an
 * empty container is, because there is nothing inside it to type.
 */
$table = [
    'cache.enabled' => 'bool',
    // Text, not a boolean. The whole reason the reader refuses to convert.
    'cache.warm' => 'string',
    'diagnostics.embed' => 'bool',
    // A date is seconds since the epoch, so this leaf is an integer and not the string somebody wrote.
    'diagnostics.since' => 'int',
    'documents.default.description' => 'string',
    // Present and null. Distinct from absent, and nothing strips it.
    'documents.default.error_responses' => 'null',
    'documents.default.servers.0.description' => 'string',
    'documents.default.servers.0.url' => 'string',
    'documents.default.servers.1.description' => 'string',
    'documents.default.servers.1.url' => 'string',
    // An empty container written out, which is why it is a leaf here rather than a branch.
    'documents.default.tags' => 'array',
    'documents.default.title' => 'string',
    // Quoted in the file, so it survives as text rather than becoming the float 1.1.
    'documents.default.version' => 'string',
    'documents.v2.title' => 'string',
    // Unquoted in the file, so a version number arrived as a number. An INT, because the parser is
    // free to read that spelling either way and the reader settles it — see ConfigVersionStabilityTest.
    'documents.v2.version' => 'int',
    'documents.v3.title' => 'string',
    // Also unquoted, and not an integer, so nothing settles it: it stays the float the parser gave.
    'documents.v3.version' => 'float',
    'engine.memory_limit' => 'int',
    'engine.mode' => 'string',
    'engine.paths.0' => 'string',
    'engine.paths.1' => 'string',
    'extensions' => 'array',
    'lint.fail_on' => 'string',
    'lint.leakage.patterns.absolute-path' => 'string',
    'lint.leakage.patterns.internal-host' => 'string',
    'lint.rules.operation-summary' => 'bool',
    'lint.rules.operation-tags' => 'bool',
];

it('parses the representative configuration to the bytes committed beside it', function () use ($fixture): void {
    $read = ConfigFile::parse((string) file_get_contents($fixture));

    expect($read->ok())->toBeTrue()
        ->and($read->error)->toBeNull()
        ->and($read->diagnostics)->toBe([]);

    // Order-preserving on purpose: sorting the keys would hide a reordering.
    //
    // Zero-fraction-preserving is belt-and-braces rather than load-bearing, and worth a line so it is
    // not deleted as dead. The reader settles every integral float to an int, so nothing in a resolved
    // parse can carry a fraction to preserve — the flag changes no byte today. It earns its place on
    // the day that settling regresses: without it an escaped float 1.0 would render as `1` and match
    // the golden anyway, and this net would be the one that stayed quiet. The invariant itself is
    // asserted in ConfigVersionStabilityTest, which does not depend on an encoder's flags at all.
    $json = json_encode(
        $read->values,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
    )."\n";

    // Written here rather than through assertGolden(), which addresses the ADAPTER's golden directory
    // and looks past a generator version. Neither applies: this is a core golden, and a parsed config
    // bag carries no generator member for a comparison to normalise.
    $golden = dirname(__DIR__).'/Fixtures/golden/config-parse.json';
    if (getenv('DOCUCCINO_UPDATE_GOLDEN') === '1') {
        file_put_contents($golden, $json);
    }

    expect($json)->toBe((string) file_get_contents($golden));
});

it('types every leaf of the representative parse, and names no leaf the parse does not produce', function () use ($fixture, $table): void {
    $read = ConfigFile::parse((string) file_get_contents($fixture));

    // The walk reads the parsed values; the table above was typed out. Comparing the two sets in both
    // directions is what makes a table short by a row a failure rather than a silence.
    $walk = static function (array $node, string $prefix) use (&$walk): array {
        $leaves = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== []) {
                $leaves = [...$leaves, ...$walk($value, $path)];

                continue;
            }

            $leaves[$path] = get_debug_type($value);
        }

        return $leaves;
    };

    $leaves = $walk($read->values, '');
    ksort($leaves, SORT_STRING);

    expect($leaves)->toBe($table);
});

it('walks a fixture deep and wide enough for the table to mean something', function () use ($fixture, $table): void {
    // A walk that matched nothing, or a fixture flattened to three keys, would agree with a table
    // trimmed to match and pass forever. Both floors are well under what the fixture holds.
    $depths = array_map(static fn (string $path): int => substr_count($path, '.'), array_keys($table));

    expect(count($table))->toBeGreaterThanOrEqual(20)
        ->and(max($depths))->toBeGreaterThanOrEqual(4)
        ->and(array_unique(array_values($table)))->toContain('string', 'int', 'float', 'bool', 'null', 'array')
        ->and(file_get_contents($fixture))->toBeString();
});
