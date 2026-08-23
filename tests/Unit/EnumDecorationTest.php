<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\EnumDecoration;

/**
 * The one enum-decoration rulebook, exercised directly: every `enums.naming` keyword the table knows
 * plus the unknown-keyword degradation, the completeness contract of the value-keyed description map,
 * and the JSON shape that map has to keep.
 */

/**
 * @param  list<string|int>  $values
 * @param  list<string>  $names
 * @param  array<string, string>  $descriptions
 * @return array<string, mixed>
 */
function decorateEnum(array $values, string $naming = 'names', array $names = [], array $descriptions = []): array
{
    return EnumDecoration::apply(['type' => 'string', 'enum' => $values], $naming, $names, $descriptions);
}

it('emits the hint keys each naming keyword names, and none for one it does not know', function (string $naming, array $expected): void {
    $schema = decorateEnum(['draft', 'live'], $naming, ['Draft', 'Live']);

    expect(array_keys(array_diff_key($schema, ['type' => null, 'enum' => null])))->toBe($expected);
})->with([
    // `names` is the default, so a typo is the newly-relevant degradation: hints silently off.
    'names emits both spellings' => ['names', ['x-enum-varnames', 'x-enumNames']],
    'x-enumNames pins one tool' => ['x-enumNames', ['x-enumNames']],
    'x-enum-varnames pins the other' => ['x-enum-varnames', ['x-enum-varnames']],
    'none turns hints off' => ['none', []],
    'an unknown keyword emits no hints' => ['x-enum-varname', []],
    'an empty keyword emits no hints' => ['', []],
]);

it('withholds name hints that do not line up one-to-one with the values', function (array $names): void {
    expect(decorateEnum(['draft', 'live'], names: $names))->toBe(['type' => 'string', 'enum' => ['draft', 'live']]);
})->with([
    'no names at all' => [[]],
    'a short array would rename a prefix downstream' => [['Draft']],
    'a long array would name a value that is not there' => [['Draft', 'Live', 'Archived']],
]);

it('emits the value-keyed map only when every value has prose, the index array whenever any does', function (): void {
    $partial = decorateEnum(['draft', 'live'], descriptions: ['draft' => 'Not yet.']);
    $complete = decorateEnum(['draft', 'live'], descriptions: ['draft' => 'Not yet.', 'live' => 'Serving.']);

    expect($partial)->not->toHaveKey('x-enumDescriptions')
        ->and($partial['x-enum-descriptions'])->toBe(['Not yet.', ''])
        ->and($complete['x-enumDescriptions'])->toBe(['draft' => 'Not yet.', 'live' => 'Serving.'])
        ->and($complete['x-enum-descriptions'])->toBe(['Not yet.', 'Serving.']);
});

it('emits nothing at all when no value has prose', function (): void {
    expect(decorateEnum(['draft', 'live'], 'none'))->toBe(['type' => 'string', 'enum' => ['draft', 'live']]);
});

/**
 * PHP re-coerces the numeric-string keys of a `0,1,2` backing run straight back to ints, which makes
 * the completed map a LIST — and `["a","b","c"]` is not the object every consumer of this extension
 * reads. The keys an int-backed enum publishes are exactly the ones that trip it.
 */
it('emits the descriptions map as a JSON object however its keys look', function (array $values, array $descriptions, string $encoded): void {
    $schema = decorateEnum($values, descriptions: $descriptions);

    expect(json_encode($schema['x-enumDescriptions']))->toBe($encoded);
})->with([
    'a contiguous zero-based int run' => [
        [0, 1, 2],
        ['0' => 'Free.', '1' => 'Standard.', '2' => 'Premium.'],
        '{"0":"Free.","1":"Standard.","2":"Premium."}',
    ],
    'a single zero-valued case' => [[0], ['0' => 'Free.'], '{"0":"Free."}'],
    'a gapped int run is already a map' => [[1, 5], ['1' => 'Low.', '5' => 'High.'], '{"1":"Low.","5":"High."}'],
    'an int run not starting at zero' => [[1, 2], ['1' => 'One.', '2' => 'Two.'], '{"1":"One.","2":"Two."}'],
    'string values' => [['draft', 'live'], ['draft' => 'Not yet.', 'live' => 'Serving.'], '{"draft":"Not yet.","live":"Serving."}'],
]);

it('leaves a schema without an enum member alone', function (mixed $enum): void {
    $schema = EnumDecoration::apply(['type' => 'string', 'enum' => $enum], 'names', ['Draft'], ['draft' => 'Not yet.']);

    expect($schema)->toBe(['type' => 'string', 'enum' => $enum]);
})->with([
    'an empty enum' => [[]],
    'a non-array enum' => ['draft'],
]);
