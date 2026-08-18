<?php

declare(strict_types=1);

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
