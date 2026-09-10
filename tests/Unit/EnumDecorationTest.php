<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Extensions\Schema\EnumDecoration;

/**
 * The one enum-decoration rulebook, exercised directly: every `enums.naming` keyword the table knows
 * plus the unknown-keyword degradation, the completeness contract of the value-keyed description map,
 * and the JSON shape that map has to keep.
 */

/**
 * @param  list<mixed>  $values
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

/**
 * Every decoration shape is positional, and `enum` is a value list the canonicalizer holds each value
 * once in. Decorating the list as handed in therefore leaves the names and the index-parallel prose
 * one longer than the values they describe — so every member past the repeat takes the previous one's
 * name and prose in a generated client, which is a confidently wrong answer rather than a missing one.
 */
it('decorates the values it publishes, not the ones it was handed', function (): void {
    $schema = decorateEnum(
        ['name', 'name', '-total'],
        'names',
        ['Name', 'Name', 'TotalDesc'],
        ['name' => 'By name.', '-total' => 'Total, descending.'],
    );

    expect($schema)->toBe([
        'type' => 'string',
        'enum' => ['name', '-total'],
        'x-enumDescriptions' => ['name' => 'By name.', '-total' => 'Total, descending.'],
        'x-enum-descriptions' => ['By name.', 'Total, descending.'],
        'x-enum-varnames' => ['Name', 'TotalDesc'],
        'x-enumNames' => ['Name', 'TotalDesc'],
    ]);
});

/**
 * The alignment check is a length comparison, so it is only worth what it is compared against: the
 * canonicalizer is what actually publishes `enum`, and a decoration that agreed with the minted list
 * and not with that one would pass every check here and still ship the slide.
 *
 * So the rule is stated from the contract rather than from either side's code — entry i of every
 * parallel array names value i of the enum the DOCUMENT carries — and the rows include the values two
 * readings of sameness disagree about: `1` and `"1"` are two enum members, and a repeat can sit
 * anywhere. Each occurrence of a repeated value is given its own name, so a name that slid one place
 * shows up as the wrong name rather than as the same one twice.
 */
it('names the value at each published index, whatever the canonicalizer held back', function (array $values, array $names, array $enum, array $expected): void {
    $schema = decorateEnum($values, 'names', $names);

    $canonical = (new Canonicalizer)->canonicalize([
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => ['/a' => ['get' => ['responses' => ['200' => [
            'description' => 'ok',
            'content' => ['application/json' => ['schema' => $schema]],
        ]]]]],
    ]);

    $published = $canonical['paths']['/a']['get']['responses']['200']['content']['application/json']['schema'];

    // The decoration's own answer and the document's have to be the same list, or the names are
    // parallel to a list nobody publishes.
    expect($schema['enum'])->toBe($enum)
        ->and($published['enum'])->toBe($enum)
        ->and($published['x-enum-varnames'])->toBe($expected)
        ->and($published['x-enumNames'])->toBe($expected);
})->with([
    'a value stated twice' => [['name', 'name', '-total'], ['Name', 'NameAgain', 'TotalDesc'], ['name', '-total'], ['Name', 'TotalDesc']],
    'a value stated three times' => [['a', 'a', 'a'], ['A', 'ASecond', 'AThird'], ['a'], ['A']],
    'a repeat that is not adjacent' => [[1, 2, 1], ['One', 'Two', 'OneAgain'], [1, 2], ['One', 'Two']],
    'an int and its string spelling are two values' => [[1, '1'], ['One', 'OneText'], [1, '1'], ['One', 'OneText']],
    'true and 1 are two values' => [[true, 1], ['Yes', 'One'], [true, 1], ['Yes', 'One']],
    'nothing repeated' => [['draft', 'live'], ['Draft', 'Live'], ['draft', 'live'], ['Draft', 'Live']],
]);

/** Names that never lined up are dropped, deduping or not — a short array renames a prefix. */
it('drops name hints that do not line up with the published values', function (): void {
    $schema = decorateEnum(['name', 'name', '-total'], 'names', ['Name', 'TotalDesc']);

    expect($schema)->toBe(['type' => 'string', 'enum' => ['name', '-total']]);
});
