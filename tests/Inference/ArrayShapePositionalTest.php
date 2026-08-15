<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;

/**
 * `isList` is the one fact that decides whether a constant shape documents as a JSON array or a JSON
 * object, and PHP settles it from the keys alone: an array renders as a JSON array exactly while its
 * keys are the `0..n` sequence. So the flag is DERIVED here as well as taken from the caller — a
 * recovering path that only sees the keys (the docblock grammar reading `array{string, int}`) cannot
 * hand on a tuple that later reads as an object with `"0"`/`"1"` property names.
 */
it('derives isList from the keys, whatever the caller passed', function (array $fields, bool $passed, bool $expected): void {
    expect((new ArrayShapeT($fields, $passed))->isList)->toBe($expected);
})->with([
    'positional, unflagged' => [[new ArrayShapeField(0, ScalarT::string()), new ArrayShapeField(1, ScalarT::int())], false, true],
    'positional, flagged' => [[new ArrayShapeField(0, ScalarT::string())], true, true],
    'a single index 0' => [[new ArrayShapeField(0, ScalarT::string())], false, true],
    // Not a `0..n` sequence: PHP renders these as JSON objects with numeric-string keys.
    'starting past zero' => [[new ArrayShapeField(1, ScalarT::string()), new ArrayShapeField(2, ScalarT::int())], false, false],
    'sparse' => [[new ArrayShapeField(0, ScalarT::string()), new ArrayShapeField(5, ScalarT::int())], false, false],
    'out of order' => [[new ArrayShapeField(1, ScalarT::string()), new ArrayShapeField(0, ScalarT::int())], false, false],
    'named keys' => [[new ArrayShapeField('id', ScalarT::int())], false, false],
    'mixing an index in with a name' => [[new ArrayShapeField(0, ScalarT::string()), new ArrayShapeField('id', ScalarT::int())], false, false],
    // An empty shape says nothing either way, so the caller's answer stands.
    'empty, unflagged' => [[], false, false],
    'empty, flagged' => [[], true, true],
]);

it('round-trips the derived flag through serialization', function (): void {
    $shape = new ArrayShapeT([new ArrayShapeField(0, ScalarT::string()), new ArrayShapeField(1, ScalarT::int())]);

    expect($shape->toArray()['isList'])->toBeTrue()
        ->and(ArrayShapeT::fromArray($shape->toArray())->isList)->toBeTrue();
});

it('emits a positional shape as an array schema, never as an object keyed by its indices', function (): void {
    // `"properties": {"0": …, "1": …}` is a PHP list, so it serialises as a JSON ARRAY — structurally
    // invalid. A vague-but-valid array of the member union is the honest answer for a tuple.
    $converter = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry);

    $schema = $converter->toSchema(new ArrayShapeT([
        new ArrayShapeField(0, ScalarT::string()),
        new ArrayShapeField(1, ScalarT::int()),
    ]))->schema;

    expect($schema)->toBe([
        'type' => 'array',
        'items' => ['anyOf' => [['type' => 'integer'], ['type' => 'string']]],
    ]);
});

it('emits a sparse int-keyed shape as the object PHP really renders', function (): void {
    // Keys 1 and 5 are not a list, so PHP renders `{"1": …, "5": …}` — an object with numeric-string
    // property names, which IS a legal JSON Schema shape.
    $converter = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry);

    $schema = $converter->toSchema(new ArrayShapeT([
        new ArrayShapeField(1, ScalarT::string()),
        new ArrayShapeField(5, ScalarT::int()),
    ]))->schema;

    expect($schema)->toBe([
        'type' => 'object',
        'properties' => ['1' => ['type' => 'string'], '5' => ['type' => 'integer']],
        'required' => ['1', '5'],
    ]);
});
