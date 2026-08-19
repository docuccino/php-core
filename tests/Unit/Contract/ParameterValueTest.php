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
