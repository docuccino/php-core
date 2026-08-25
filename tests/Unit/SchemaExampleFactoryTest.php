<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Contract\Examples\ExampleReport;
use Docuccino\Core\Emit\SchemaExampleFactory;

/**
 * Building one representative value from a schema. Every choice is a pure function of the schema, and
 * the ones with more than one defensible answer are pinned here so they cannot drift into depending on
 * key order.
 */
function example(array $schema, array $components = []): mixed
{
    return (new SchemaExampleFactory)->value($schema, $components);
}

/**
 * The example as an artifact carries it — `{}` for an empty object, never `[]`.
 */
function exampleJson(array $schema, array $components = []): string
{
    return (string) json_encode(example($schema, $components));
}

/**
 * One value held to one schema through the same {@see ExampleAudit} every build runs over an example
 * somebody wrote by hand. Planted rather than generated in place, because `example` is the first member
 * the factory reads: generate, then plant, or the check only proves the factory can echo.
 */
function auditAgainst(mixed $value, array $schema, array $components = []): ExampleReport
{
    $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
    $schema['example'] = $value;
    $schemas['Subject'] = $schema;

    return (new ExampleAudit(ContractIndex::fromArray([
        'paths' => [],
        'components' => ['schemas' => $schemas],
    ])))->run();
}

/**
 * Every reason the audit would give for refusing $value against $schema — a violation, or a schema it
 * would not read at all. Both, because an uncheckable site is not a pass.
 *
 * @return list<string>
 */
function auditRefusals(mixed $value, array $schema, array $components = []): array
{
    $report = auditAgainst($value, $schema, $components);

    $out = [];
    foreach ($report->findings as $finding) {
        foreach ($finding->violations as $violation) {
            $out[] = $violation->message;
        }
    }

    foreach ($report->uncheckable as $site) {
        $out[] = 'uncheckable: '.$site->reason;
    }

    return $out;
}

it('prefers what the schema states outright, in a fixed order', function (array $schema, mixed $expected): void {
    expect(example($schema))->toBe($expected);
})->with([
    'example wins over everything' => [['type' => 'string', 'example' => 'stated', 'default' => 'd', 'enum' => ['e']], 'stated'],
    'examples list next' => [['type' => 'string', 'examples' => ['first', 'second'], 'default' => 'd'], 'first'],
    'OAS named example unwraps its value' => [['type' => 'string', 'examples' => [['value' => 'wrapped']]], 'wrapped'],
    'default next' => [['type' => 'string', 'default' => 'defaulted', 'enum' => ['e']], 'defaulted'],
    'const next' => [['type' => 'string', 'const' => 'fixed'], 'fixed'],
    'enum last' => [['type' => 'string', 'enum' => ['chosen', 'other']], 'chosen'],
    'a stated null is still a value' => [['type' => 'string', 'example' => null], null],
]);

it('picks a named example by lowest key, not by the order it was written', function (): void {
    // `examples` is a map keyed by author-chosen names; that order is not a decision anyone made.
    $schema = ['type' => 'string', 'examples' => ['zulu' => ['value' => 'z'], 'alpha' => ['value' => 'a']]];
    $reversed = ['type' => 'string', 'examples' => ['alpha' => ['value' => 'a'], 'zulu' => ['value' => 'z']]];

    expect(example($schema))->toBe('a')
        ->and(example($reversed))->toBe('a');
});

it('samples every declared string format, and degrades an unknown one', function (string $format, string $expected): void {
    expect(example(['type' => 'string', 'format' => $format]))->toBe($expected);
})->with([
    'date-time' => ['date-time', '2024-01-01T00:00:00Z'],
    'date' => ['date', '2024-01-01'],
    'time' => ['time', '00:00:00'],
    'duration' => ['duration', 'P1D'],
    'email' => ['email', 'user@example.com'],
    'idn-email' => ['idn-email', 'user@example.com'],
    'uuid' => ['uuid', '3fa85f64-5717-4562-b3fc-2c963f66afa6'],
    'uri' => ['uri', 'https://example.com'],
    'uri-reference' => ['uri-reference', '/example'],
    'url' => ['url', 'https://example.com'],
    'hostname' => ['hostname', 'example.com'],
    'ipv4' => ['ipv4', '192.0.2.1'],
    'ipv6' => ['ipv6', '2001:db8::1'],
    'byte' => ['byte', 'ZXhhbXBsZQ=='],
    'binary' => ['binary', ''],
    'password' => ['password', 'secret'],
    // The unknown-entry degradation every lookup table owes.
    'unknown' => ['klingon-stardate', 'string'],
]);

it('builds a value for each type', function (array $schema, mixed $expected): void {
    expect(example($schema))->toBe($expected);
})->with([
    'string' => [['type' => 'string'], 'string'],
    'integer' => [['type' => 'integer'], 0],
    'integer honours its minimum' => [['type' => 'integer', 'minimum' => 7], 7],
    'number' => [['type' => 'number'], 0],
    'boolean' => [['type' => 'boolean'], true],
    'null' => [['type' => 'null'], null],
    'nullable picks the non-null half' => [['type' => ['string', 'null']], 'string'],
    'all-null type list' => [['type' => ['null']], null],
    'unknown type' => [['type' => 'quantum'], null],
    'no type at all' => [[], null],
]);

it('shows every declared property, not only the required ones', function (): void {
    // A body someone is about to edit should show the whole shape; hiding the optional half hides the
    // contract they are being asked to satisfy.
    expect(example([
        'type' => 'object',
        'required' => ['id'],
        'properties' => ['name' => ['type' => 'string'], 'id' => ['type' => 'integer']],
    ]))->toBe(['id' => 0, 'name' => 'string']);
});

it('returns an empty OBJECT for an object with no properties', function (): void {
    // The value is serialised into a JSON string; an empty PHP array would render `[]`, which is a body
    // that lies about its own shape.
    expect(example(['type' => 'object']))->toBeInstanceOf(stdClass::class);
});

it('builds exactly one array item, whatever minItems asks for', function (): void {
    expect(example(['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 25]))->toBe(['string'])
        ->and(example(['type' => 'array']))->toBe([]);
});

it('reads an object from its keywords when no type is declared', function (): void {
    expect(example(['properties' => ['a' => ['type' => 'integer']]]))->toBe(['a' => 0])
        ->and(example(['items' => ['type' => 'integer']]))->toBe([0]);
});

it('merges allOf branches left to right', function (): void {
    expect(example(['allOf' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
        ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
    ]]))->toBe(['a' => 0, 'b' => 'string']);
});

it('takes the first branch of oneOf and anyOf', function (string $keyword): void {
    // The branch LIST is authored, unlike a map's keys, and the first branch is what every other reader
    // of the document shows.
    expect(example([$keyword => [['type' => 'integer'], ['type' => 'string']]]))->toBe(0);
})->with(['oneOf', 'anyOf']);

it('resolves a component reference', function (): void {
    $components = ['schemas' => ['Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]];

    expect(example(['$ref' => '#/components/schemas/Thing'], $components))->toBe(['id' => 0]);
});

it('stops at a reference it cannot resolve rather than guessing', function (string $ref): void {
    expect(example(['$ref' => $ref], ['schemas' => []]))->toBeInstanceOf(stdClass::class);
})->with([
    'dangling' => ['#/components/schemas/Missing'],
    'external' => ['https://example.com/schema.json#/Thing'],
    'not a component pointer' => ['#/definitions/Thing'],
]);

it('terminates on a self-referential schema', function (): void {
    // Self-referential trees are common enough that this guard is not optional.
    $components = ['schemas' => ['Node' => [
        'type' => 'object',
        'properties' => ['child' => ['$ref' => '#/components/schemas/Node']],
    ]]];

    $value = example(['$ref' => '#/components/schemas/Node'], $components);

    expect($value)->toBeArray()
        ->and($value['child'])->toBeInstanceOf(stdClass::class);
});

it('stops descending past its depth cap', function (): void {
    // Built inside out: 12 levels of nesting, deeper than the cap.
    $schema = ['type' => 'string'];
    for ($i = 0; $i < 12; $i++) {
        $schema = ['type' => 'object', 'properties' => ['down' => $schema]];
    }

    $value = example($schema);
    $depth = 0;
    while (is_array($value) && isset($value['down'])) {
        $value = $value['down'];
        $depth++;
    }

    expect($depth)->toBeLessThan(12)
        ->and($value)->toBeInstanceOf(stdClass::class);
});

it('is a pure function of the schema', function (): void {
    $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']]];

    expect(example($schema))->toBe(example($schema));
});

/*
 * A boolean IS a schema at every subschema position, and this factory read one as an absent member — so
 * `properties: {legacy: false}` published `{"legacy": null}`, a value its own schema forbids, into a
 * Postman body a consumer copies and sends. `true` is the empty schema, so it reads exactly as `{}`
 * does; `false` admits nothing, and nothing is not a value this factory may invent.
 *
 * Each row carries the byte the reader USED to publish where that byte was forbidden, and the audit
 * refuses it below — so the row proves the defect as well as the fix.
 */
it('reads a boolean subschema as the schema it is, at every position it reads one', function (array $schema, string $expected, ?string $before, array $components): void {
    expect(exampleJson($schema, $components))->toBe($expected);

    // The generated example held to its own schema, exactly as a hand-written one is on every build.
    $value = example($schema, $components);

    expect(auditAgainst($value, $schema, $components)->checked)->toBeGreaterThan(0)
        ->and(auditRefusals($value, $schema, $components))->toBe([]);

    if ($before !== null) {
        expect(auditRefusals(json_decode($before, false, flags: JSON_THROW_ON_ERROR), $schema, $components))->not->toBe([]);
    }
})->with([
    // properties — a forbidden member is left out, and the object without it is what validates.
    'properties: false' => [
        ['type' => 'object', 'properties' => ['legacy' => false, 'name' => ['type' => 'string']]],
        '{"name":"string"}',
        '{"legacy":null,"name":"string"}',
        [],
    ],
    'properties: true' => [
        ['type' => 'object', 'properties' => ['any' => true, 'name' => ['type' => 'string']]],
        '{"any":null,"name":"string"}',
        null,
        [],
    ],
    // items — `false` leaves the empty array as the only array that validates.
    'items: false' => [['type' => 'array', 'items' => false], '[]', null, []],
    'items: true' => [['type' => 'array', 'items' => true], '[null]', null, []],
    // additionalProperties — read for the object it implies, never for a value, so both booleans agree.
    'additionalProperties: false' => [['additionalProperties' => false], '{}', null, []],
    'additionalProperties: true' => [['additionalProperties' => true], '{}', null, []],
    // A union walks past a branch nothing satisfies: `false` is not an alternative any consumer has.
    'anyOf: [false, …]' => [['anyOf' => [false, ['type' => 'string']]], '"string"', 'null', []],
    'anyOf: [true, …]' => [['anyOf' => [true, ['type' => 'string']]], 'null', null, []],
    'oneOf: [false, …]' => [['oneOf' => [false, ['type' => 'integer']]], '0', 'null', []],
    'oneOf: [true, …]' => [['oneOf' => [true, ['type' => 'integer']]], 'null', null, []],
    // A conjunction with a `true` branch is the conjunction without it.
    'allOf: [true, …]' => [['allOf' => [true, ['type' => 'string']]], '"string"', null, []],
    // And a boolean behind a reference is the boolean, not a pointer that failed to resolve.
    '$ref to a true component' => [
        ['$ref' => '#/components/schemas/Any'],
        'null',
        null,
        ['schemas' => ['Any' => true]],
    ],
    'a property whose $ref forbids everything' => [
        ['type' => 'object', 'properties' => ['x' => ['$ref' => '#/components/schemas/Never'], 'y' => ['type' => 'string']]],
        '{"y":"string"}',
        '{"x":{},"y":"string"}',
        ['schemas' => ['Never' => false]],
    ],
]);

/*
 * Where a schema admits NO value there is no honest byte, and the factory does not get to invent one —
 * so it says `null` and leaves the rest to whoever asked. At a nested position that decision is made
 * above (a property omits itself, an array stays empty, a union takes another branch), which the rows
 * above cover; here the unsatisfiable schema is the whole one.
 *
 * Each row names the byte the reader used to publish, and the audit refuses every one — which is the
 * defect stated as a test: a value published beside a schema that rejects it.
 */
it('stops publishing a value where its own schema admits none', function (array $schema, string $before, array $components): void {
    expect(example($schema, $components))->toBeNull()
        ->and(auditRefusals(json_decode($before, false, flags: JSON_THROW_ON_ERROR), $schema, $components))->not->toBe([]);
})->with([
    'a REQUIRED property nothing satisfies' => [
        ['type' => 'object', 'required' => ['legacy'], 'properties' => ['legacy' => false]],
        '{"legacy":null}',
        [],
    ],
    'allOf with a false branch' => [
        ['allOf' => [false, ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
        '{"a":"string"}',
        [],
    ],
    // The `false` sits AFTER the branch that answered, so a reader returning on the first answer never
    // reaches it. A conjunction is as narrow as its narrowest branch wherever that branch sits.
    'allOf whose false branch follows a scalar one' => [
        ['allOf' => [['type' => 'string'], false]],
        '"string"',
        [],
    ],
    'a union of nothing but false' => [['anyOf' => [false, false]], 'null', []],
    '$ref to a false component' => [
        ['$ref' => '#/components/schemas/Never'],
        '{}',
        ['schemas' => ['Never' => false]],
    ],
]);

/*
 * The durable half. This factory reads the subschema keywords in its own right, and the guard on stale
 * COPIES of that set cannot see it: it reads six across five methods, and no one declaration enumerates
 * three. It also has no uniform per-position action to derive — `items: false` means the empty array
 * and `not: false` means anything at all — so what is owed here is COVERAGE, not a derived set.
 *
 * The keywords come from the SOURCE rather than from a list, so teaching the factory to read `not` or
 * `contains` fails this until the boolean case at that position has been decided too.
 */
it('pins a boolean at every subschema position the factory reads', function (): void {
    $read = subschemaKeywordsNamedIn((string) file_get_contents(
        dirname(__DIR__, 2).'/src/Emit/SchemaExampleFactory.php',
    ));

    // Anti-vacuity: a scan that stopped seeing the factory's keywords must fail, not pass forever.
    expect($read)->toContain('items', 'properties', 'allOf', 'anyOf', 'oneOf')
        ->and(count($read))->toBeGreaterThan(4)
        // Every one has a row in the dataset above. A seventh keyword lands here first.
        ->and($read)->toBe(['additionalProperties', 'allOf', 'anyOf', 'items', 'oneOf', 'properties']);
});
