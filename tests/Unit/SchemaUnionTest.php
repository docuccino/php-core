<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\SchemaUnion;

/**
 * The one expression of a union. Every producer that special-cases one member of one — a serialised
 * date-time, a cast column's wire shape — assembles here, so what this answers is what the document
 * says about nullability everywhere; a producer rolling its own is how a member gets dropped.
 */
it('assembles every member count, nullable and not, under both policies', function (
    array $members,
    bool $nullable,
    string $policy,
    array $expected,
): void {
    expect(SchemaUnion::of($members, $nullable, $policy))->toBe($expected);
})->with([
    // Nothing survived. `null` alone is still true; an empty set says nothing, which is the honest
    // degradation — never a fabricated type.
    'no members, not nullable' => [[], false, 'type-array', []],
    'no members, nullable' => [[], true, 'type-array', ['type' => 'null']],
    'no members, nullable, anyof' => [[], true, 'anyof', ['type' => 'null']],

    // One member: the member itself, or folded/branched per policy.
    'one member, not nullable' => [[['type' => 'string']], false, 'type-array', ['type' => 'string']],
    'one member, nullable' => [[['type' => 'string']], true, 'type-array', ['type' => ['string', 'null']]],
    'one member, nullable, anyof' => [
        [['type' => 'string']],
        true,
        'anyof',
        ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
    ],

    // Two or more: an anyOf, with the null branch last so the shape is a function of the set.
    'two members, not nullable' => [
        [['type' => 'integer'], ['type' => 'string']],
        false,
        'type-array',
        ['anyOf' => [['type' => 'integer'], ['type' => 'string']]],
    ],
    'two members, nullable' => [
        [['type' => 'integer'], ['type' => 'string']],
        true,
        'type-array',
        ['anyOf' => [['type' => 'integer'], ['type' => 'string'], ['type' => 'null']]],
    ],
    'two members, nullable, anyof' => [
        [['type' => 'integer'], ['type' => 'string']],
        true,
        'anyof',
        ['anyOf' => [['type' => 'integer'], ['type' => 'string'], ['type' => 'null']]],
    ],
]);

it('keeps every keyword the member carried when it folds the null in', function (): void {
    // The whole point of contributing a member rather than replacing the union: a `format`, a
    // `description` or an `enum` the producer worked out survives being made nullable.
    expect(SchemaUnion::of([['type' => 'string', 'format' => 'date-time']], true, 'type-array'))
        ->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and(SchemaUnion::of([['type' => 'integer', 'description' => 'Unix timestamp (seconds).']], true, 'type-array'))
        ->toBe(['type' => ['integer', 'null'], 'description' => 'Unix timestamp (seconds).']);
});

it('widens every fragment shape a producer can hand it', function (array $schema, string $policy, array $expected): void {
    expect(SchemaUnion::nullable($schema, $policy))->toBe($expected);
})->with([
    // A simple type folds; so does a type LIST, which is what a cast admitting an object OR an array is.
    'a named type' => [['type' => 'string'], 'type-array', ['type' => ['string', 'null']]],
    'a type list' => [['type' => ['array', 'object']], 'type-array', ['type' => ['array', 'object', 'null']]],

    // A `$ref` cannot carry `type: [x, null]`, so it takes a branch. Leaving it alone is the defect:
    // the schema then forbids the null the API really sends, and a generated client types it non-null.
    '$ref' => [
        ['$ref' => '#/components/schemas/AccountStatus'],
        'type-array',
        ['anyOf' => [['$ref' => '#/components/schemas/AccountStatus'], ['type' => 'null']]],
    ],
    'an anyOf' => [
        ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
        'type-array',
        ['anyOf' => [['anyOf' => [['type' => 'string'], ['type' => 'integer']]], ['type' => 'null']]],
    ],
    'no type at all' => [[], 'type-array', ['anyOf' => [[], ['type' => 'null']]]],

    // The anyof policy branches everything, so one producer cannot express nullability in a shape the
    // rest of the document does not use.
    'a named type under anyof' => [
        ['type' => 'string'],
        'anyof',
        ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
    ],
    'a type list under anyof' => [
        ['type' => ['array', 'object']],
        'anyof',
        ['anyOf' => [['type' => ['array', 'object']], ['type' => 'null']]],
    ],
]);

it('is idempotent on a fragment that already admits null', function (array $schema, string $policy): void {
    // A producer may widen a fragment another producer already widened; a second pass must not nest a
    // branch or list `null` twice, or the same code path emits two shapes for one fact.
    expect(SchemaUnion::nullable($schema, $policy))->toBe($schema)
        ->and(SchemaUnion::nullable(SchemaUnion::nullable($schema, $policy), $policy))->toBe($schema);
})->with([
    'null itself' => [['type' => 'null'], 'type-array'],
    'a nullable named type' => [['type' => ['string', 'null']], 'type-array'],
    'a nullable type list' => [['type' => ['array', 'object', 'null']], 'type-array'],
    'null itself under anyof' => [['type' => 'null'], 'anyof'],
    'a nullable named type under anyof' => [['type' => ['string', 'null']], 'anyof'],
]);

it('defaults to the type-array policy, the shape most consumers handle best', function (): void {
    expect(SchemaUnion::of([['type' => 'string']], true))->toBe(['type' => ['string', 'null']])
        ->and(SchemaUnion::nullable(['type' => 'string']))->toBe(['type' => ['string', 'null']]);
});

it('is a function of the member set, not of the order it was called', function (): void {
    // Determinism AND locality: the same members in the same order always assemble the same bytes, and
    // nothing about a previous call leaks into the next one.
    $members = [['type' => 'integer'], ['type' => 'string', 'format' => 'date-time']];

    $first = SchemaUnion::of($members, true, 'type-array');
    SchemaUnion::of([['type' => 'boolean']], false, 'anyof');
    SchemaUnion::nullable(['$ref' => '#/components/schemas/X'], 'anyof');

    expect(SchemaUnion::of($members, true, 'type-array'))->toBe($first);
});
