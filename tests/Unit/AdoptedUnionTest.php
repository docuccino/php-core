<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Validation\AdoptedUnion;

/*
 * A declared union written over the object the rules split by a tag. Which declarations adopt the split,
 * and what each tag value's refinement carries, read off the bodies alone — the component bodies the
 * declaration names, and the branches the rules left in place.
 */

$member = static fn (string $kind, array $properties, array $required = []): array => [
    'type' => 'object',
    'properties' => ['kind' => ['type' => 'string', 'const' => $kind]] + $properties,
    'required' => ['kind', ...$required],
];

$schemas = static fn (array $members, string $union = 'Shape'): array => [
    $union => ['anyOf' => array_map(static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name], array_keys($members))],
] + $members;

$split = static fn (array ...$branches): array => ['anyOf' => [...$branches, ['type' => 'null']], 'description' => 'From the rules.'];

it('refines the declared union by tag value with what only the rules state', function () use ($member, $schemas, $split): void {
    $adopted = AdoptedUnion::over(
        ['$ref' => '#/components/schemas/Shape'],
        $split(
            $member('round', ['radius' => ['type' => 'number', 'minimum' => 0, 'description' => 'Rule prose.']], ['radius']),
            $member('square', ['side' => ['type' => 'integer', 'maximum' => 9], 'label' => ['type' => 'string', 'maxLength' => 3, 'example' => 'abc']], ['side', 'label']),
        ),
        $schemas([
            'Round' => $member('round', ['radius' => ['type' => 'number']], ['radius']),
            'Square' => $member('square', ['side' => ['type' => 'integer', 'maximum' => 5]], ['side']),
        ]),
        'shape',
    );

    expect($adopted?->schema)->toEqual([
        // The rules' own annotations stand where the declaration says nothing of its own.
        'description' => 'From the rules.',
        '$ref' => '#/components/schemas/Shape',
        'anyOf' => [
            // A bound the declared member does not state; its prose is the declaration's to write.
            ['properties' => ['kind' => ['const' => 'round'], 'radius' => ['minimum' => 0]]],
            // The declared `maximum` is the tighter, so the rules' adds nothing beside it; a member the
            // declaration lacks is the rules' to describe, and one they require it leaves optional is required.
            ['properties' => ['kind' => ['const' => 'square'], 'label' => ['type' => 'string', 'maxLength' => 3]], 'required' => ['label']],
        ],
    ])
        // The declared `maximum` refuses a side the server takes, and the declaration admits no null
        // where the rules do; `label` is required by the rules and listed by no declared member.
        ->and($adopted?->wider)->toBe(['`shape.side` where `kind` is square', '`shape`, which the rules let be null'])
        ->and($adopted?->unlisted)->toBe(['`shape.label` where `kind` is square']);
});

it('publishes the declared reference alone where the rules state nothing it does not', function () use ($member, $schemas, $split): void {
    $adopted = AdoptedUnion::over(
        ['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']], 'description' => 'Declared.'],
        $split($member('round', []), $member('square', [])),
        $schemas(['Round' => $member('round', []), 'Square' => $member('square', [])]),
        'shape',
    );

    expect($adopted?->schema)->toEqual(['description' => 'Declared.', 'anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']]])
        ->and($adopted?->wider)->toBe([]);
});

it('notes each member where the rules accept a type the declaration does not', function () use ($member, $schemas, $split): void {
    $adopted = AdoptedUnion::over(
        ['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']]],
        $split(
            $member('round', ['radius' => ['type' => 'number']]),
            $member('square', ['side' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]]]),
        ),
        $schemas([
            'Round' => $member('round', ['radius' => ['type' => 'integer']]),
            // A number admits every integer, so a declared number under an integer rule is no narrowing.
            'Square' => $member('square', ['side' => ['type' => 'number'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]),
        ]),
        'shape',
    );

    expect($adopted?->wider)->toBe(['`shape.radius` where `kind` is round', '`shape.tags.*` where `kind` is square']);
});

it('reads a declared member through its reference to refine what is inside it', function () use ($member, $schemas, $split): void {
    $adopted = AdoptedUnion::over(
        ['$ref' => '#/components/schemas/Shape'],
        $split(
            $member('round', ['centre' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer', 'minimum' => 0]]]]),
            $member('square', []),
        ),
        $schemas([
            'Round' => $member('round', ['centre' => ['$ref' => '#/components/schemas/Point']]),
            'Square' => $member('square', []),
        ]) + ['Point' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]],
        'shape',
    );

    expect($adopted?->schema['anyOf'][0] ?? null)->toBe(['properties' => ['kind' => ['const' => 'round'], 'centre' => ['properties' => ['x' => ['minimum' => 0]]]]]);
});

it('leaves to the declared-shape rule what it cannot adopt', function (array $declared, array $standing, array $schemas): void {
    expect(AdoptedUnion::over($declared, $standing, $schemas, 'shape'))->toBeNull();
})->with(function () use ($member, $schemas, $split): array {
    $two = $schemas(['Round' => $member('round', []), 'Square' => $member('square', [])]);
    $branches = $split($member('round', []), $member('square', []));

    return [
        'a declared scalar' => [['type' => 'string'], $branches, $two],
        'a declared reference to an object' => [['$ref' => '#/components/schemas/Round'], $branches, $two],
        'a reference to nothing registered' => [['$ref' => '#/components/schemas/Missing'], $branches, $two],
        'a union written inline' => [['anyOf' => [['$ref' => '#/components/schemas/Round'], ['$ref' => '#/components/schemas/Square']]], $branches, $two],
        'a union of one' => [['$ref' => '#/components/schemas/Shape'], $branches, $schemas(['Round' => $member('round', [])])],
        'a union with an inline member' => [['$ref' => '#/components/schemas/Shape'], $branches, ['Shape' => ['anyOf' => [['$ref' => '#/components/schemas/Round'], ['type' => 'object']]], 'Round' => $member('round', [])]],
        'an object the rules left merged' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]], $two],
        'branches told apart by nothing' => [['$ref' => '#/components/schemas/Shape'], ['anyOf' => [['properties' => ['a' => ['type' => 'string']]], ['properties' => ['b' => ['type' => 'string']]]]], $two],
    ];
});

it('says why a declared tagged union does not match the rules\' one', function (array $members, string $reason) use ($schemas, $split, $member): void {
    $adopted = AdoptedUnion::over(['$ref' => '#/components/schemas/Shape'], $split($member('round', []), $member('square', [])), $schemas($members), 'shape');

    expect($adopted?->schema)->toBeNull()
        ->and($adopted?->mismatch)->toBe($reason);
})->with(function () use ($member): array {
    $typed = static fn (string $type, array $properties = []): array => [
        'type' => 'object',
        'properties' => ['type' => ['type' => 'string', 'const' => $type]] + $properties,
        'required' => ['type'],
    ];

    return [
        'other values' => [
            ['Round' => $member('round', []), 'Oval' => $member('oval', [])],
            'the rules accept one shape per `kind` (round, square), and the declared type is told apart by `kind` (oval, round)',
        ],
        'another tag' => [
            ['Round' => $typed('round'), 'Square' => $typed('square')],
            'the rules accept one shape per `kind` (round, square), and the declared type is told apart by `type` (round, square)',
        ],
        'a value shared' => [
            ['Round' => $member('round', []), 'Square' => $member('round', [])],
            'the rules accept one shape per `kind` (round, square), and the declared type is told apart by no property its members each fix to a value of their own',
        ],
    ];
});

it('publishes what both the declaration and the rules accept where both bound a member', function (array $rule, array $declared, array $refinement, bool $narrower) use ($member, $schemas): void {
    // Beside the `$ref` the declared member and the refinement both hold, so the published bound is the
    // tighter of the two: a declared bound looser than the rule's gets the rule's beside it, and one tighter
    // refuses a value the server takes — which the author is told, the declaration being what is published.
    $adopted = AdoptedUnion::over(
        ['$ref' => '#/components/schemas/Shape'],
        ['anyOf' => [$member('round', ['unit' => ['type' => 'string'] + $rule]), $member('square', [])]],
        $schemas(['Round' => $member('round', ['unit' => ['type' => 'string'] + $declared]), 'Square' => $member('square', [])]),
        'shape',
    );

    $published = $adopted?->schema['anyOf'][0]['properties']['unit'] ?? [];

    expect($published)->toBe($refinement)
        ->and($adopted?->wider)->toBe($narrower ? ['`shape.unit` where `kind` is round'] : []);
})->with([
    'a declared enum missing a value the rule accepts' => [['enum' => ['cm', 'mm', 'in']], ['enum' => ['cm', 'mm']], [], true],
    'a declared enum holding a value the rule refuses' => [['enum' => ['cm', 'mm']], ['enum' => ['cm', 'mm', 'in']], ['enum' => ['cm', 'mm']], false],
    'two enums each refusing a value the other accepts' => [['enum' => ['mm', 'in']], ['enum' => ['cm', 'mm']], ['enum' => ['mm', 'in']], true],
    'the same enum' => [['enum' => ['cm', 'mm']], ['enum' => ['cm', 'mm']], [], false],
    'a declared const the rule refuses' => [['const' => 'cm'], ['const' => 'mm'], ['const' => 'cm'], true],
    'a declared ceiling above the rule\'s' => [['maxLength' => 50], ['maxLength' => 100], ['maxLength' => 50], false],
    'a declared ceiling below the rule\'s' => [['maxLength' => 100], ['maxLength' => 50], [], true],
    'a declared floor below the rule\'s' => [['minLength' => 2], ['minLength' => 1], ['minLength' => 2], false],
    'a declared floor above the rule\'s' => [['minLength' => 1], ['minLength' => 2], [], true],
    // Two patterns nothing orders: both are true of what the server takes, and neither is called narrower.
    'another pattern' => [['pattern' => '^[a-z]+$'], ['pattern' => '^[a-m]+$'], ['pattern' => '^[a-z]+$'], false],
]);

it('offers null only where the rules accept it too', function () use ($member, $schemas): void {
    // A declared `?Shape` over rules that refuse null: the server answers a null with a 422, so the null
    // the declaration admits is not published — the declaration and the rules both hold.
    $adopted = AdoptedUnion::over(
        ['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']]],
        ['anyOf' => [$member('round', []), $member('square', [])]],
        $schemas(['Round' => $member('round', []), 'Square' => $member('square', [])]),
        'shape',
    );

    expect($adopted?->schema)->toBe(['$ref' => '#/components/schemas/Shape'])
        ->and($adopted?->wider)->toBe([]);
});

it('adopts a union whose component is written as a oneOf', function () use ($member): void {
    $adopted = AdoptedUnion::over(
        ['$ref' => '#/components/schemas/Shape'],
        ['anyOf' => [$member('round', ['radius' => ['type' => 'number', 'minimum' => 0]]), $member('square', [])]],
        [
            'Shape' => ['oneOf' => [['$ref' => '#/components/schemas/Round'], ['$ref' => '#/components/schemas/Square']]],
            'Round' => $member('round', ['radius' => ['type' => 'number']]),
            'Square' => $member('square', []),
        ],
        'shape',
    );

    expect($adopted?->schema['anyOf'][0] ?? null)->toBe(['properties' => ['kind' => ['const' => 'round'], 'radius' => ['minimum' => 0]]]);
});

it('reads an empty object the rules accept in either key order', function () use ($member, $schemas): void {
    $adopted = AdoptedUnion::over(
        ['$ref' => '#/components/schemas/Shape'],
        ['anyOf' => [$member('round', []), $member('square', []), ['maxProperties' => 0, 'type' => 'object']]],
        $schemas(['Round' => $member('round', []), 'Square' => $member('square', [])]),
        'shape',
    );

    expect($adopted?->schema)->toBe(['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'object', 'maxProperties' => 0]]]);
});

it('names whether a declared field is a union it could adopt by', function (array $declared, bool $names) use ($member, $schemas): void {
    $registered = $schemas(['Round' => $member('round', []), 'Square' => $member('square', [])]);

    expect(AdoptedUnion::namesUnion($declared, $registered))->toBe($names);
})->with([
    'the union' => [['$ref' => '#/components/schemas/Shape'], true],
    'the union, nullable' => [['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']]], true],
    'a description alone' => [['description' => 'Anything.'], false],
    'a scalar' => [['type' => 'string'], false],
    'one member' => [['$ref' => '#/components/schemas/Round'], false],
]);
