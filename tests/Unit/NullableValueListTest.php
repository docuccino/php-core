<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\SchemaUnion;
use Docuccino\Core\Extensions\Validation\FieldNode;
use Opis\JsonSchema\Validator;

/*
 * A value the server accepts as null must validate as null wherever the document widens a value list
 * to it — the response side ({@see SchemaUnion::nullable()}), the request side ({@see FieldNode}) and
 * the 3.0 downlevel of either. The rule is stated here as JSON Schema states it, by running a validator
 * over the published shape, rather than by asking either site what it does: two sites that widen alike
 * can still both be wrong.
 */

/**
 * Whether `$value` validates against `$schema`, read as JSON — an empty PHP array would otherwise be a
 * list rather than the `{}` schema.
 *
 * @param  array<string, mixed>  $schema
 */
function admits(array $schema, mixed $value): bool
{
    $asJson = json_decode((string) json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

    return (new Validator)->validate(json_decode((string) json_encode($value)), $asJson)->isValid();
}

/**
 * The same widening through each site, for one value-list schema under one policy: each published
 * schema, and whether it admits a value as its own dialect reads it.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, array{schema: array<string, mixed>, admits: Closure(mixed): bool}>
 */
function nullableThroughEverySite(array $schema, string $policy): array
{
    $node = new FieldNode;
    $node->keywords = $schema;
    $node->nullable = true;

    $sites = [
        'response' => SchemaUnion::nullable($schema, $policy),
        'request' => $node->build(new RepresentationPolicy(nullable: $policy)),
    ];

    $read = [];
    foreach ($sites as $site => $widened) {
        $read[$site] = ['schema' => $widened, 'admits' => static fn (mixed $value): bool => admits($widened, $value)];
    }

    // Each through the 3.0 downlevel too, read as 3.0 reads it: its `nullable` is not JSON Schema.
    foreach ($sites as $site => $widened) {
        $emitted = (new OpenApi30DownlevelEmitter)->emit(UirDocument::fromArray([
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => ['Field' => $widened]],
        ]));
        /** @var array<string, mixed> $downlevel */
        $downlevel = json_decode($emitted, true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['Field'];
        $read[$site.' (3.0)'] = [
            'schema' => $downlevel,
            'admits' => static fn (mixed $value): bool => openApi30Admits($emitted, '/components/schemas/Field', $value),
        ];
    }

    return $read;
}

it('admits null, every listed value, and nothing else, at every site that widens a value list', function (array $schema, array $members, mixed $outsider, string $policy): void {
    foreach (nullableThroughEverySite($schema, $policy) as $site => $widened) {
        expect(($widened['admits'])(null))->toBeTrue($site.' refuses null')
            ->and(($widened['admits'])($outsider))->toBeFalse($site.' admits a value outside the list');
        foreach ($members as $member) {
            expect(($widened['admits'])($member))->toBeTrue($site.' refuses '.json_encode($member));
        }
    }
})->with([
    'an enum' => [['type' => 'string', 'enum' => ['draft', 'live']], ['draft', 'live'], 'gone'],
    'a decorated enum' => [['type' => 'string', 'enum' => ['draft', 'live'], 'x-enum-varnames' => ['Draft', 'Live']], ['draft', 'live'], 'gone'],
    'an integer enum' => [['type' => 'integer', 'enum' => [1, 2]], [1, 2], 3],
    'a const' => [['type' => 'string', 'const' => 'open'], ['open'], 'closed'],
    'an untyped enum' => [['enum' => ['a', 'b']], ['a', 'b'], 'c'],
    'an enum with prose and a pattern' => [['type' => 'string', 'enum' => ['ab', 'cd'], 'pattern' => '^[a-z]+$', 'description' => 'Two letters.'], ['ab', 'cd'], 'ef'],
])->with(['type-array', 'anyof']);

it('keeps an enum\'s name hints parallel to its values wherever it is widened', function (string $policy): void {
    $schema = ['type' => 'string', 'enum' => ['draft', 'live'], 'x-enum-varnames' => ['Draft', 'Live'], 'x-enumNames' => ['Draft', 'Live']];

    foreach (nullableThroughEverySite($schema, $policy) as $site => ['schema' => $widened]) {
        $holder = isset($widened['anyOf']) ? $widened['anyOf'][0] : $widened;
        $values = array_values(array_filter($holder['enum'], static fn (mixed $v): bool => $v !== null));

        // Positional hints name the listed values in order; a null appended after them is named by none.
        expect($holder['x-enum-varnames'])->toHaveCount(count($values), $site)
            ->and(array_slice($holder['enum'], 0, count($values)))->toBe($values, $site);
    }
})->with(['type-array', 'anyof']);

it('leaves a value list that already lists null folded, and a type list beside it alone', function (): void {
    $listed = ['type' => 'string', 'enum' => ['a', null]];

    expect(SchemaUnion::valuesRefuseNull($listed))->toBeFalse()
        ->and(SchemaUnion::nullable($listed))->toBe(['type' => ['string', 'null'], 'enum' => ['a', null]])
        ->and(SchemaUnion::valuesRefuseNull(['type' => 'string', 'const' => null]))->toBeFalse()
        ->and(SchemaUnion::valuesRefuseNull(['type' => 'string']))->toBeFalse()
        ->and(SchemaUnion::valuesRefuseNull(['type' => 'string', 'const' => 'a']))->toBeTrue();
});
