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
    // not — `false` admits nothing, so a `not` of it forbids nothing: the constraint is simply absent.
    'not: false' => [['type' => 'string', 'not' => false], '"string"', null, []],
    // contains — `true` is matched by any element at all, so the array only has to be non-empty.
    'contains: true' => [['type' => 'array', 'contains' => true], '[null]', '[]', []],
    'contains: true beside items' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => true],
        '["string"]',
        null,
        [],
    ],
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
    // `true` admits everything, so a `not` of it admits nothing — the mirror of `not: false` above.
    'not: true' => [['type' => 'string', 'not' => true], '"string"', []],
    // No element can match a `contains` of `false`, and an array must carry one match.
    'contains: false' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => false],
        '["string"]',
        [],
    ],
    '$ref to a false component' => [
        ['$ref' => '#/components/schemas/Never'],
        '{}',
        ['schemas' => ['Never' => false]],
    ],
]);

/*
 * Two keywords say what a value may NOT be, and an example published past one is a body the server
 * rejects — which the consumer discovers by sending it. `not` forbids whatever its subschema admits, and
 * every `not_in:` rule the validation integration recovers writes one; `contains` forbids an array with
 * no element matching its subschema, so an array whose `items` and `contains` disagree has no
 * single-element instance at all.
 *
 * Neither is satisfiable by construction, so the factory PROVES the constraint met or publishes nothing.
 * Here is the half it proves: each row's value is held to its own schema through the same audit a build
 * runs over a hand-written example, and where the old reader published a forbidden byte the row carries
 * it and the audit refuses it.
 */
it('keeps the example where the value provably escapes a constraining keyword', function (array $schema, string $expected, ?string $before): void {
    expect(exampleJson($schema))->toBe($expected);

    $value = example($schema);

    expect(auditAgainst($value, $schema)->checked)->toBeGreaterThan(0)
        ->and(auditRefusals($value, $schema))->toBe([]);

    if ($before !== null) {
        expect(auditRefusals(json_decode($before, false, flags: JSON_THROW_ON_ERROR), $schema))->not->toBe([]);
    }
})->with([
    // The shape of every `not_in:` rule: a value set the sample provably is not in.
    'not a value set the sample escapes' => [
        ['type' => 'string', 'not' => ['enum' => ['admin', 'root']]],
        '"string"',
        null,
    ],
    'not a const the sample escapes' => [['type' => 'string', 'not' => ['const' => 'admin']], '"string"', null],
    'not a type the sample cannot be' => [['type' => 'string', 'not' => ['type' => 'integer']], '"string"', null],
    'not an integer set the sample escapes' => [['type' => 'integer', 'not' => ['enum' => [1, 2]]], '0', null],
    // contains — the `items` element already matches, so the array is the one the shape produces anyway.
    'contains what items already produces' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'string']],
        '["string"]',
        null,
    ],
    // Nothing says what the elements are, so the match itself is the element.
    'contains with no items to answer to' => [
        ['type' => 'array', 'contains' => ['type' => 'integer']],
        '[0]',
        '[]',
    ],
    'contains a const with no items to answer to' => [
        ['type' => 'array', 'contains' => ['const' => 'wanted']],
        '["wanted"]',
        '[]',
    ],
    // The match is built from `contains` and `items` is not known to refuse it.
    'contains a const items does not refuse' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'wanted']],
        '["wanted"]',
        '["string"]',
    ],
    // `minContains: 0` empties the keyword: every array matches it, the empty one included.
    'contains under minContains: 0' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'integer'], 'minContains' => 0],
        '["string"]',
        null,
    ],
    // A cap with no floor is still something to satisfy: nothing has to match, but what may match is
    // bounded — so the one-element array carries the match rather than avoiding it.
    'contains bounded above with no floor' => [
        [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'contains' => ['const' => 'wanted'],
            'minContains' => 0,
            'maxContains' => 2,
        ],
        '["wanted"]',
        '["string"]',
    ],
    // One match is never fewer than a floor of 1 and never more than a cap of 1 or above.
    'contains bounded either side of the one match' => [
        [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'contains' => ['const' => 'wanted'],
            'minContains' => 1,
            'maxContains' => 1,
        ],
        '["wanted"]',
        '["string"]',
    ],
]);

/*
 * And the half it cannot prove. A constraint the factory would have to guess at makes the example
 * ABSENT: a missing example costs a consumer a convenience, and a forbidden one costs them a failed
 * request. Every row names the byte the old reader published, and the audit refuses each one.
 */
it('publishes no example where a constraining keyword forbids what it would have published', function (array $schema, string $before, array $components): void {
    expect(example($schema, $components))->toBeNull()
        ->and(auditRefusals(json_decode($before, false, flags: JSON_THROW_ON_ERROR), $schema, $components))->not->toBe([]);
})->with([
    // The sharpest case in the report: a `const` upstream naming the very value `not` forbids.
    'not the const the schema states' => [['const' => 'admin', 'not' => ['const' => 'admin']], '"admin"', []],
    'not the enum entry the schema picks' => [
        ['type' => 'string', 'enum' => ['admin', 'other'], 'not' => ['enum' => ['admin']]],
        '"admin"',
        [],
    ],
    'not the author example beside it' => [
        ['type' => 'string', 'example' => 'admin', 'not' => ['const' => 'admin']],
        '"admin"',
        [],
    ],
    'not the sample format supplies' => [
        ['type' => 'string', 'format' => 'email', 'not' => ['const' => 'user@example.com']],
        '"user@example.com"',
        [],
    ],
    // `{}` admits everything, so a `not` of it admits nothing — `contains: {}` mislies the same way.
    'not the empty schema' => [['type' => 'string', 'not' => []], '"string"', []],
    // A `not` this factory cannot decide is not a `not` it may publish past: widen to no example rather
    // than guess the sample escapes it.
    'not a constraint the factory does not check' => [
        ['type' => 'string', 'not' => ['type' => 'string', 'pattern' => '^str']],
        '"string"',
        [],
    ],
    // The report's array case: `contains` wants an integer and `items` allows only strings, so no
    // single-element array satisfies both.
    'contains a type items forbids' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'integer']],
        '["string"]',
        [],
    ],
    'contains a match the factory cannot prove it built' => [
        ['type' => 'array', 'contains' => ['type' => 'string', 'pattern' => '^a']],
        '[]',
        [],
    ],
    // `items` speaks about EVERY element, the match built from `contains` included — so an `items` this
    // factory cannot decide refuses that element exactly as a proven mismatch does. Both rows published
    // the match before, past an `items` neither audit nor factory reads as admitting it.
    'contains a const beside a length items does not decide' => [
        ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3], 'contains' => ['const' => 'abcdef']],
        '["abcdef"]',
        [],
    ],
    'contains a const beside a pattern items does not decide' => [
        ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z]$'], 'contains' => ['const' => 'LONG']],
        '["LONG"]',
        [],
    ],
    // One element is the only length this factory builds, and a floor above 1 wants an array of repeats
    // — the answer a `uniqueItems` beside it forbids, which this factory does not read.
    'contains twice over, with items to build from' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'string'], 'minContains' => 2],
        '["string"]',
        [],
    ],
    'contains three times over, with nothing to build from' => [
        ['type' => 'array', 'contains' => ['type' => 'integer'], 'minContains' => 3],
        '[0]',
        [],
    ],
    // A cap below 1 forbids the one match this factory can prove, and leaves only an array whose
    // elements provably do NOT match — which is the one thing `items` and `contains` cannot prove.
    'contains what nothing may match' => [
        ['type' => 'array', 'contains' => ['type' => 'integer'], 'maxContains' => 0],
        '[0]',
        [],
    ],
    'contains what nothing may match, floor and all' => [
        [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'contains' => ['type' => 'string'],
            'minContains' => 0,
            'maxContains' => 0,
        ],
        '["string"]',
        [],
    ],
    // A `not` sits beside a `$ref` as legally as it sits alone, and the reference resolving first is not
    // a reason the constraint goes unread.
    'not beside a reference' => [
        ['$ref' => '#/components/schemas/Thing', 'not' => true],
        '"string"',
        ['schemas' => ['Thing' => ['type' => 'string']]],
    ],
]);

/*
 * A member the constraint forbids is answered by the POSITION, exactly as a `false` there is: the object
 * leaves an optional one out, and has no instance at all where it is required.
 */
it('answers a forbidden member at the position holding it', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => [
            'kept' => ['type' => 'string', 'not' => ['const' => 'admin']],
            'gone' => ['const' => 'admin', 'not' => ['const' => 'admin']],
        ],
    ];

    expect(exampleJson($schema))->toBe('{"kept":"string"}')
        ->and(example(['type' => 'object', 'required' => ['gone'], 'properties' => $schema['properties']]))->toBeNull();
});

/*
 * Every arm of the three-valued prover, which is what decides both constraining keywords and so what
 * every row above rests on. Admitted, refused and CANNOT TELL are three different answers and only one
 * of them publishes: reversing an arm either ships a value the schema forbids or withholds one it
 * allows, and neither shows up as a test failure unless the arm is pinned here.
 *
 * Read through `member()`, the one public surface where a published `null` and a withheld example are
 * distinguishable — `value()` collapses both to `null`, which is exactly why the positions ask.
 */
it('publishes only where the prover proves the constraining keyword escaped', function (array $schema, ?string $published): void {
    $member = (new SchemaExampleFactory)->member($schema);

    expect($member === null ? null : json_encode($member[0]))->toBe($published);
})->with([
    // A `not` that is no schema at all widens to `{}` exactly as a subschema position widens it, and `{}`
    // admits everything — so there is nothing here to publish.
    'a not that is no schema at all' => [['type' => 'string', 'not' => 42], null],
    // An `enum` with no members, and one that is no list: both are sets this factory will not read, and
    // an unread set is not a set the value provably escapes.
    'a not enum with no members' => [['type' => 'string', 'not' => ['enum' => []]], null],
    'a not enum that is no list' => [['type' => 'string', 'not' => ['enum' => 'admin']], null],
    // A `type` may name a LIST of types, and the value belongs to the set or it does not.
    'a not type list the value is outside' => [['type' => 'string', 'not' => ['type' => ['integer', 'boolean']]], '"string"'],
    'a not type list the value is inside' => [['type' => 'string', 'not' => ['type' => ['string', 'null']]], null],
    'a not type that is no type at all' => [['type' => 'string', 'not' => ['type' => 42]], null],
    // Two composites are never compared — object member order is not an authored fact — while a
    // composite against a scalar is different whatever is inside it, either way round.
    'a not const composite against a composite' => [['const' => ['a' => 1], 'not' => ['const' => ['a' => 1]]], null],
    'a not const composite against a scalar' => [['type' => 'string', 'not' => ['const' => ['a' => 1]]], '"string"'],
    'a not const scalar against a composite' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'not' => ['const' => 'admin']],
        '{"a":"string"}',
    ],
    // `null` is a JSON type like any other, and a published `null` is a value rather than a silence.
    'a not type a null value is outside' => [['const' => null, 'not' => ['type' => 'string']], 'null'],
    'a not type a null value is inside' => [['const' => null, 'not' => ['type' => 'null']], null],
    // The empty object is a stdClass here and never `[]`, so a keyed array is an object and every other
    // array a list. Read the three the wrong way round and each of these flips.
    'a not type a list is outside' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'not' => ['type' => 'object']],
        '["string"]',
    ],
    'a not type a list is inside' => [['type' => 'array', 'items' => ['type' => 'string'], 'not' => ['type' => 'array']], null],
    'a not type a keyed object is outside' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'not' => ['type' => 'array']],
        '{"a":"string"}',
    ],
    'a not type the empty object is outside' => [['type' => 'object', 'not' => ['type' => 'array']], '{}'],
    'a not type the empty object is inside' => [['type' => 'object', 'not' => ['type' => 'object']], null],
    // An integral number is an `integer` and every integer is also a `number`; `1` and `1.0` are one
    // JSON instance, whichever way PHP happens to be holding them.
    'a not type an integral float is inside' => [['const' => 1.0, 'not' => ['type' => 'integer']], null],
    'a not type a fractional float is outside' => [['const' => 1.5, 'not' => ['type' => 'integer']], '1.5'],
    'a not number type an integer is inside' => [['const' => 1, 'not' => ['type' => 'number']], null],
    'a not const across the two spellings of one number' => [['const' => 1, 'not' => ['const' => 1.0]], null],
    // A keyword that says nothing about the instance is passed over rather than making the answer
    // undecidable — so a `not` of nothing but annotations admits everything, and nothing publishes.
    'a not of nothing but an annotation' => [['type' => 'string', 'not' => ['description' => 'anything']], null],
    // Every member provably different settles an `enum` too, composites included.
    'a not enum of members the value is none of' => [['type' => 'string', 'not' => ['enum' => [['a'], 'b']]], '"string"'],
    'a not enum holding the value' => [['type' => 'string', 'not' => ['enum' => ['string', 'other']]], null],
]);

/*
 * The durable half. This factory reads the subschema keywords in its own right, and the guard on stale
 * COPIES of that set cannot see it: it reads eight across six methods, and no one declaration
 * enumerates three. It also has no uniform per-position action to derive — `items: false` means the empty
 * array and `not: false` means anything at all — so what is owed here is COVERAGE, not a derived set.
 *
 * The keywords come from the SOURCE rather than from a list, so teaching the factory to read
 * `propertyNames` or `prefixItems` fails this until the boolean case at that position has been decided
 * too.
 */
it('pins a boolean at every subschema position the factory reads', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Emit/SchemaExampleFactory.php');
    $read = subschemaKeywordsNamedIn($source);

    // Anti-vacuity: a scan that stopped seeing the factory's keywords must fail, not pass forever.
    expect($read)->toContain('items', 'properties', 'allOf', 'anyOf', 'oneOf', 'not', 'contains')
        ->and(count($read))->toBeGreaterThan(6)
        // Every one has a row in the datasets above. A ninth keyword lands here first.
        ->and($read)->toBe([
            'additionalProperties', 'allOf', 'anyOf', 'contains', 'items', 'not', 'oneOf', 'properties',
        ]);

    // The guard EXECUTED rather than claimed: a factory that had learned another position reads as a
    // longer list here, which is what makes this fail rather than quietly go short.
    expect(subschemaKeywordsNamedIn($source.'<?php $x = $schema["propertyNames"];'))
        ->toBe([...$read, 'propertyNames']);
});
