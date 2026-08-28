<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ParameterValue;

it('reads a string back as the type the contract documents, and leaves it alone otherwise', function (mixed $value, ?array $schema, mixed $expected): void {
    expect(ParameterValue::coerce($value, $schema))->toBe($expected);
})->with([
    'an integer' => ['42', ['type' => 'integer'], 42],
    'a negative integer' => ['-7', ['type' => 'integer'], -7],
    'a nullable integer' => ['42', ['type' => ['integer', 'null']], 42],
    'a number' => ['12.5', ['type' => 'number'], 12.5],
    'an integer literal for a number' => ['12', ['type' => 'number'], 12.0],
    'true' => ['true', ['type' => 'boolean'], true],
    'false' => ['false', ['type' => 'boolean'], false],
    'one' => ['1', ['type' => 'boolean'], true],
    'zero' => ['0', ['type' => 'boolean'], false],
    'a string that is not an integer stays a string' => ['first', ['type' => 'integer'], 'first'],
    'a float where an integer is documented stays a string' => ['1.5', ['type' => 'integer'], '1.5'],
    'a word where a boolean is documented stays a string' => ['yes', ['type' => 'boolean'], 'yes'],
    'a string documented as a string' => ['42', ['type' => 'string'], '42'],
    'no schema at all' => ['42', null, '42'],
    'no type in the schema' => ['42', ['minimum' => 1], '42'],
    'a type that is not a string or a list' => ['42', ['type' => 42], '42'],
    'a list of types with a non-string in it' => ['42', ['type' => [42, 'integer']], 42],
    'a value that is already an integer' => [42, ['type' => 'integer'], 42],
    'a value that is already a bool' => [true, ['type' => 'boolean'], true],
    'null' => [null, ['type' => 'integer'], null],
]);

it('splits a comma list into the array the contract documents, coercing each item', function (): void {
    expect(ParameterValue::coerce('1,2,3', ['type' => 'array', 'items' => ['type' => 'integer']]))->toBe([1, 2, 3])
        ->and(ParameterValue::coerce('a,b', ['type' => 'array']))->toBe(['a', 'b'])
        ->and(ParameterValue::coerce('a', ['type' => 'array', 'items' => ['type' => 'string']]))->toBe(['a']);
});

it('coerces the items of a list that already arrived as one', function (): void {
    expect(ParameterValue::coerce(['1', '2'], ['type' => 'array', 'items' => ['type' => 'integer']]))->toBe([1, 2]);
});

it('reads a bracketed query parameter as the object it stands for', function (): void {
    $coerced = ParameterValue::coerce(
        ['status' => 'paid', 'total' => '12'],
        ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]],
    );

    expect($coerced)->toBeInstanceOf(stdClass::class)
        ->and($coerced->status)->toBe('paid')
        ->and($coerced->total)->toBe(12);
});

it('keeps a map documented as an array as an array', function (): void {
    expect(ParameterValue::coerce(['a' => '1', 'b' => '2'], ['type' => 'array', 'items' => ['type' => 'integer']]))
        ->toBe(['1', '2']);
});

it('ignores a properties member that is not a map of schemas', function (): void {
    $coerced = ParameterValue::coerce(['a' => '1'], ['type' => 'object', 'properties' => ['a' => 'nope']]);

    expect($coerced->a)->toBe('1');
});

it('reads the type through the same grammar the validator resolves, not off the node in front of it', function (?array $schema, mixed $expected): void {
    // Every spelling here is one the generator itself emits: `representation.nullable = 'anyof'`
    // writes the `anyOf`, the 3.0 downlevel emitter writes the multi-type `anyOf` and the `allOf`
    // wrapper it hoists `$ref` siblings into, and an enum-backed allow-list writes the `$ref`.
    expect(ParameterValue::coerce('1000', $schema, contractSchemaDocument()))->toBe($expected);
})->with([
    'a literal type' => [['type' => 'integer'], 1000],
    'a literal type beside an extension member' => [['type' => 'integer', 'x-docuccino' => ['id' => 'x']], 1000],
    'a nullable type array' => [['type' => ['integer', 'null']], 1000],
    'an anyOf' => [['anyOf' => [['type' => 'integer'], ['type' => 'null']]], 1000],
    'a oneOf' => [['oneOf' => [['type' => 'integer'], ['type' => 'null']]], 1000],
    'an allOf' => [['allOf' => [['type' => 'integer']]], 1000],
    'a $ref' => [['$ref' => '#/components/schemas/PerPage'], 1000],
    'a $ref hoisted into an allOf beside its siblings' => [
        ['allOf' => [['$ref' => '#/components/schemas/PerPage'], ['maximum' => 100]]], 1000,
    ],
    'a $ref chain' => [['$ref' => '#/components/schemas/PerPageAlias'], 1000],
    'a $ref inside an anyOf' => [['anyOf' => [['$ref' => '#/components/schemas/PerPage'], ['type' => 'null']]], 1000],
    'an enum with no type of its own' => [['enum' => [10, 25, 1000]], 1000],
    'an enum behind a $ref' => [['$ref' => '#/components/schemas/UntypedSizes'], 1000],
]);

it('leaves a string alone where the contract says nothing it can read a type from', function (?array $schema): void {
    expect(ParameterValue::coerce('1000', $schema, contractSchemaDocument()))->toBe('1000');
})->with([
    // A reference the document does not define is not a quiet "no coercion": SchemaCheck cannot
    // resolve it either, so the check fails naming the pointer (ContractCheckerTest pins that).
    'a $ref at a name nothing defines' => [['$ref' => '#/components/schemas/Ghost']],
    // A sibling does not stand in for the half that would not resolve. The node means "whatever Ghost
    // says AND an integer", and half of that is unreadable — so the type is unknown, not `integer`.
    'a $ref at a name nothing defines, beside a type of its own' => [['$ref' => '#/components/schemas/Ghost', 'type' => 'integer']],
    'a draft-07 definitions pointer' => [['$ref' => '#/definitions/PerPage']],
    'a reference into another file' => [['$ref' => 'other.json#/PerPage']],
    'a $ref that composes its way back to itself' => [['$ref' => '#/components/schemas/Cycle']],
    'a composition nested past the depth bound' => [array_reduce(
        range(1, 12),
        static fn (array $carry, int $level): array => ['allOf' => [$carry]],
        ['type' => 'integer'],
    )],
    'a composition keyword that is not a list of schemas' => [['anyOf' => 'integer']],
    'a branch that is not a schema' => [['anyOf' => ['integer', 42]]],
    'an empty schema' => [[]],
    'no schema at all' => [null],
]);

it('leaves a string alone where the contract permits a string, whatever else it permits', function (?array $schema): void {
    // The value already satisfies the contract as it arrived, so converting can only take a pass
    // away: `anyOf: [{integer, minimum: 100}, {string}]` accepts `1000` as sent and rejects it as a
    // number. A union that admits several readings resolves toward the wire.
    expect(ParameterValue::coerce('1000', $schema, contractSchemaDocument()))->toBe('1000');
})->with([
    'an integer-or-string union' => [['anyOf' => [['type' => 'integer'], ['type' => 'string']]]],
    'a multi-type including string' => [['type' => ['integer', 'string']]],
    'an allOf that cannot be satisfied at all' => [['allOf' => [['type' => 'integer'], ['type' => 'string']]]],
    'an enum whose members are of several types' => [['enum' => [10, 'all']]],
]);

it('still refuses a string that is not unambiguously the documented type, behind a reference', function (): void {
    expect(ParameterValue::coerce('abc', ['$ref' => '#/components/schemas/PerPage'], contractSchemaDocument()))->toBe('abc')
        ->and(ParameterValue::coerce('1.5', ['anyOf' => [['type' => 'integer'], ['type' => 'null']]], contractSchemaDocument()))->toBe('1.5')
        ->and(ParameterValue::coerce('yes', ['allOf' => [['type' => 'boolean']]], contractSchemaDocument()))->toBe('yes');
});

it('reads items and properties out of the same resolution the type came from', function (): void {
    $document = contractSchemaDocument();

    expect(ParameterValue::coerce('1,2,3', ['$ref' => '#/components/schemas/SizeList'], $document))->toBe([1, 2, 3])
        ->and(ParameterValue::coerce('4,5', ['allOf' => [['$ref' => '#/components/schemas/SizeList']]], $document))->toBe([4, 5])
        ->and(ParameterValue::coerce(['size' => '7'], ['$ref' => '#/components/schemas/SizeFilter'], $document)->size)->toBe(7);
});

it('stops a schema that reaches itself at the second visit instead of following it forever', function (array $schema, array $document, mixed $expected): void {
    // A list documented as its own items is the shape a depth bound cannot hold on its own: splitting
    // a comma list hands `explode` the same string back when there is no comma, so the value never
    // shrinks and the walk restarts at zero on every entry into `items`. Followed, this segfaults PHP
    // at its stack limit — with no exception for the check above it to turn into a violation.
    expect(ParameterValue::coerce('1000', $schema, $document))->toBe($expected);
})->with([
    'a list whose items are the list itself' => [
        ['$ref' => '#/components/schemas/Tree'],
        ['components' => ['schemas' => [
            'Tree' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tree']],
        ]]],
        ['1000'],
    ],
    'a list whose items compose their way back to it' => [
        ['$ref' => '#/components/schemas/Wrap'],
        ['components' => ['schemas' => [
            'Wrap' => ['type' => 'array', 'items' => ['allOf' => [['$ref' => '#/components/schemas/Wrap']]]],
        ]]],
        ['1000'],
    ],
    'a pair of lists that are each other items' => [
        ['$ref' => '#/components/schemas/Ping'],
        ['components' => ['schemas' => [
            'Ping' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pong']],
            'Pong' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Ping']],
        ]]],
        [['1000']],
    ],
    'a list that reaches itself through an alias' => [
        ['$ref' => '#/components/schemas/Alias'],
        ['components' => ['schemas' => [
            'Alias' => ['$ref' => '#/components/schemas/Loop'],
            'Loop' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Alias']],
        ]]],
        ['1000'],
    ],
    // Cutting the branch that comes back is not cutting the node: the arm that says `integer` is still
    // read, so an entry the document does describe is still converted.
    'a list whose items are an integer or the list itself' => [
        ['$ref' => '#/components/schemas/Mixed'],
        ['components' => ['schemas' => [
            'Mixed' => ['type' => 'array', 'items' => [
                'anyOf' => [['type' => 'integer'], ['$ref' => '#/components/schemas/Mixed']],
            ]],
        ]]],
        [1000],
    ],
    'a composition that references its way back to itself' => [
        ['$ref' => '#/components/schemas/Cycle'],
        ['components' => ['schemas' => [
            'Cycle' => ['allOf' => [['$ref' => '#/components/schemas/Cycle']]],
        ]]],
        '1000',
    ],
]);

it('walks a branching schema once per node rather than once per path through it', function (array $document): void {
    // A bound on how DEEP the walk goes is no bound at all on how MUCH it does: k branches followed
    // nine levels is k^9 visits of a document a few hundred bytes long. Measured before this walk
    // remembered where it had been — one coerce() call, peak memory 4MB throughout, so no memory limit
    // stops it: k=5 1.9s, k=6 8.3s, k=7 31.8s, k=8 over ninety seconds. The two rows below are that
    // walk at k=6, measured at 8.7s and 10.0s against under a millisecond each on this one — so the
    // second asserted below is a threshold neither a slow machine nor a fast one can straddle.
    $started = microtime(true);

    ParameterValue::coerce('1000', ['$ref' => '#/components/schemas/Fan'], $document);

    expect(microtime(true) - $started)->toBeLessThan(1.0);
})->with([
    // A cycle: caught by the pointers on the path, on the second visit rather than the ninth level.
    'a node whose branches all point back at it' => [
        ['components' => ['schemas' => [
            'Fan' => ['allOf' => array_fill(0, 6, ['$ref' => '#/components/schemas/Fan'])],
        ]]],
    ],
    // Not a cycle: no path repeats a pointer, so nothing on the path can catch it. This one is held
    // by the memo — every (pointer, depth, path) is walked once however many paths arrive at it.
    'a ladder of references that repeats none of them' => [
        ['components' => ['schemas' => array_reduce(
            range(1, 8),
            static fn (array $carry, int $rung): array => [
                ...$carry,
                'Rung'.$rung => ['allOf' => array_fill(0, 6, [
                    '$ref' => '#/components/schemas/'.($rung === 8 ? 'Leaf' : 'Rung'.($rung + 1)),
                ])],
            ],
            [
                'Fan' => ['allOf' => array_fill(0, 6, ['$ref' => '#/components/schemas/Rung1'])],
                'Leaf' => ['type' => 'integer'],
            ],
        )]],
    ],
]);

it('keeps reading a self-referential object as deep as the value it was sent actually goes', function (): void {
    // The other side of the cut: a step into a property CONSUMES the value, so it cannot recur without
    // end whatever the schema says. Stopping there because the schema names itself would leave
    // `filter[child][size]=3` unconverted and fail a request the document permits.
    $coerced = ParameterValue::coerce(
        ['child' => ['size' => '3'], 'size' => '7'],
        ['$ref' => '#/components/schemas/Node'],
        ['components' => ['schemas' => [
            'Node' => ['type' => 'object', 'properties' => [
                'child' => ['$ref' => '#/components/schemas/Node'],
                'size' => ['type' => 'integer'],
            ]],
        ]]],
    );

    expect($coerced->size)->toBe(7)
        ->and($coerced->child->size)->toBe(3);
});

it('decodes a comma list against its items even where the contract also permits a plain string', function (string $value, array $schema, mixed $expected): void {
    // Splitting a comma list is a decode of the REPRESENTATION, not a reading of the value's type:
    // `?sort=a,evil` is a serialised list, not a string that happens to satisfy `type: string`. Left as
    // the string it arrived as, the `items` allow-list holds nothing to account and `evil` passes a
    // document that never permitted it.
    expect(ParameterValue::coerce($value, $schema))->toBe($expected);
})->with([
    'an array' => ['a,evil', ['type' => 'array', 'items' => ['enum' => ['a', 'b']]], ['a', 'evil']],
    'a string-or-array multi-type' => [
        'a,evil', ['type' => ['string', 'array'], 'items' => ['enum' => ['a', 'b']]], ['a', 'evil'],
    ],
    'a string-or-array union written as an anyOf' => [
        'a,evil',
        ['anyOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['enum' => ['a', 'b']]]]],
        ['a', 'evil'],
    ],
    // No comma is still a list of one, and the same decode: the separator is not what makes it a list.
    'a lone value against a string-or-array multi-type' => ['a', ['type' => ['string', 'array']], ['a']],
]);

it('reads a bracketed map as the object it stands for wherever the contract permits an object', function (array $schema): void {
    // The brackets have already said which of the two arrived. Read as a list instead, the keys are
    // dropped on the floor and `properties`, `required` and the `*Properties` counts all go inert:
    // `?filters[bogus]=x` re-reads as `["x"]`, which no shape of the object can fail.
    $coerced = ParameterValue::coerce(['status' => 'paid', 'due' => 'today'], $schema);

    expect($coerced)->toBeInstanceOf(stdClass::class)
        ->and($coerced->status)->toBe('paid')
        ->and($coerced->due)->toBe('today');
})->with([
    'an object' => [['type' => 'object']],
    'an array-or-object multi-type' => [['type' => ['array', 'object']]],
    'an array-or-object union written as an anyOf' => [['anyOf' => [['type' => 'array'], ['type' => 'object']]]],
]);

it('takes the type of an enum with no type of its own from every kind of member it can hold', function (string $value, array $enum, mixed $expected): void {
    // The members say what the set is, so the table that reads them owes a row per kind of member a
    // decoded document can hold. A kind read as the wrong type is a parameter converted — or left
    // alone — against a set the document does close.
    expect(ParameterValue::coerce($value, ['enum' => $enum]))->toBe($expected);
})->with([
    'null members' => ['1000', [null], '1000'],
    'boolean members' => ['true', [true, false], true],
    'integer members' => ['1000', [10, 1000], 1000],
    'float members' => ['12.5', [12.5, 25.0], 12.5],
    'list members' => ['a,b', [['a', 'b']], ['a', 'b']],
    'map members' => ['1000', [['status' => 'paid']], '1000'],
    'string members' => ['1000', ['1000', 'all'], '1000'],
    // Several kinds leave `string` in the union, which is the reading that converts nothing.
    'members of several kinds' => ['1000', [10, 'all'], '1000'],
]);
