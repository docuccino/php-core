<?php

declare(strict_types=1);

use Docuccino\Core\Diff\ComponentRefs;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\RefinedUnion;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Extensions\Validation\AdoptedUnion;
use Docuccino\Core\Support\JsonValue;

/*
 * A union of tagged components that a request refines member by member has two spellings. Beside the
 * union's pointer, `{$ref: Shape, anyOf: [refinement per tag]}`: 2020-12 applies both, and a refinement
 * pinned to one tag holds of that tag's member alone. Or the members listed, each refined one as
 * `allOf: [{$ref: member}, refinement]`, which is what a generator reads. Both are the union intersected
 * with the refinements, so moving a document from one to the other changes no contract, and a diff across
 * the move reports nothing — while a bound that really moved is still found, at the member it bounds.
 */

$member = static fn (string $kind, array $properties, array $required = []): array => [
    'type' => 'object',
    'properties' => ['kind' => ['type' => 'string', 'const' => $kind]] + $properties,
    'required' => ['kind', ...$required],
];

$components = static fn (array $members, array $more = []): array => [
    'Shape' => [
        'oneOf' => array_map(static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name], array_keys($members)),
        'discriminator' => ['propertyName' => 'kind', 'mapping' => array_combine(
            array_map(static fn (array $body): string => $body['properties']['kind']['const'], $members),
            array_map(static fn (string $name): string => '#/components/schemas/'.$name, array_keys($members)),
        )],
    ],
] + $members + $more;

$document = static fn (array $field, array $schemas, string $openapi = '3.1.1'): UirDocument => UirDocument::fromArray([
    'openapi' => $openapi,
    'info' => ['title' => 'Shapes', 'version' => '1'],
    'paths' => ['/shapes' => ['put' => [
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['shape' => $field]]]]],
        'responses' => ['204' => ['description' => 'Saved']],
    ]]],
    'components' => ['schemas' => $schemas],
]);

/** Each change as its verdict, code and field-relative pointer, sorted. */
$changes = static function (UirDocument $old, UirDocument $new): array {
    $found = array_map(
        static fn ($change): string => ($change->breaking ? 'breaking ' : '').$change->code.' '.ltrim((string) strstr($change->path, '.shape'), '.'),
        (new DocumentDiffer)->diff($old, $new)->changes,
    );
    sort($found);

    return $found;
};

$downlevel = static fn (UirDocument $document): UirDocument => UirDocument::fromArray((array) JsonValue::decode((new OpenApi30DownlevelEmitter)->emit($document)));

$scenarios = static function () use ($member, $components): array {
    $round = $member('round', ['radius' => ['type' => 'number'], 'unit' => ['type' => 'string', 'pattern' => '^[a-m]+$']], ['radius']);
    $square = $member('square', ['side' => ['type' => 'integer', 'maximum' => 5], 'centre' => ['$ref' => '#/components/schemas/Point']], ['side']);
    $schemas = $components(['Round' => $round, 'Square' => $square], ['Point' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]]);

    return [
        // The rules: a floor the member lacks, a pattern beside the member's, a member it lacks and requires,
        // and a bound through the pointer a member makes.
        'rules' => ['anyOf' => [
            $member('round', ['radius' => ['type' => 'number', 'minimum' => 0], 'unit' => ['type' => 'string', 'pattern' => '^[a-z]+$']], ['radius']),
            $member('square', [
                'side' => ['type' => 'integer'],
                'label' => ['type' => 'string', 'maxLength' => 3],
                'centre' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer', 'minimum' => 0]]],
            ], ['side', 'label']),
        ]],
        'schemas' => $schemas,
        // The same refinements beside the union's pointer, each pinned to its tag.
        'beside' => [
            '$ref' => '#/components/schemas/Shape',
            'anyOf' => [
                ['properties' => ['kind' => ['const' => 'round'], 'radius' => ['minimum' => 0], 'unit' => ['pattern' => '^[a-z]+$']]],
                ['properties' => ['kind' => ['const' => 'square'], 'label' => ['type' => 'string', 'maxLength' => 3], 'centre' => ['properties' => ['x' => ['minimum' => 0]]]], 'required' => ['label']],
            ],
        ],
    ];
};

$listed = static function (array $rules, array $schemas, bool $nullable = false): array {
    $declared = $nullable ? ['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']]] : ['$ref' => '#/components/schemas/Shape'];
    $schema = AdoptedUnion::over($declared, $rules, $schemas, 'shape')?->schema;
    expect($schema)->toBeArray();

    return (array) $schema;
};

it('reports nothing where a document moves from one spelling to the other', function (bool $nullable) use ($scenarios, $listed, $document, $changes, $downlevel): void {
    ['rules' => $rules, 'schemas' => $schemas, 'beside' => $beside] = $scenarios();
    // A nullable union sits inside an `anyOf` with the empty object and `null`, where its two spellings must
    // pair as one branch before they can be compared as one.
    $rules['anyOf'] = [...$rules['anyOf'], ...($nullable ? [['type' => 'object', 'maxProperties' => 0], ['type' => 'null']] : [])];
    $around = static fn (array $union): array => $nullable ? ['anyOf' => [$union, ['type' => 'object', 'maxProperties' => 0], ['type' => 'null']]] : $union;

    $old = $document($around($beside), $schemas);
    $new = $document($listed($rules, $schemas, $nullable), $schemas);

    expect($changes($old, $new))->toBe([])
        ->and($changes($new, $old))->toBe([])
        // 3.0 writes the pointer's siblings beside an `allOf` of it; read as 3.0, on either side or both.
        ->and($changes($downlevel($old), $downlevel($new)))->toBe([])
        ->and($changes($downlevel($old), $new))->toBe([])
        ->and($changes($old, $downlevel($new)))->toBe([])
        ->and($changes($document($around($beside), $schemas, '3.2.0'), $document($listed($rules, $schemas, $nullable), $schemas, '3.2.0')))->toBe([]);
})->with(['the union alone' => false, 'the union, nullable' => true]);

it('reports a bound that moved across the respelling, at the member it bounds', function () use ($scenarios, $listed, $document, $changes): void {
    ['rules' => $rules, 'schemas' => $schemas, 'beside' => $beside] = $scenarios();
    $rules['anyOf'][0]['properties']['radius']['minimum'] = 1;
    $rules['anyOf'][1]['properties']['label']['maxLength'] = 5;

    // A request's floor raised refuses a radius a client sent; a ceiling raised refuses nothing.
    expect($changes($document($beside, $schemas), $document($listed($rules, $schemas), $schemas)))->toBe([
        'breaking schema.refinement-narrowed shape.oneOf.0.properties.radius.minimum',
        'schema.refinement-widened shape.oneOf.1.properties.label.maxLength',
    ]);
});

it('pairs a member by what it refines when its refinement changes', function () use ($scenarios, $listed, $document, $changes): void {
    // Two members refined differently on both sides are those two members edited — not two branches gone
    // and two others arrived, which no content key could pair.
    ['rules' => $rules, 'schemas' => $schemas] = $scenarios();
    $tighter = $rules;
    $tighter['anyOf'][0]['properties']['radius']['minimum'] = 1;
    $tighter['anyOf'][1]['properties']['label']['maxLength'] = 2;

    expect($changes($document($listed($rules, $schemas), $schemas), $document($listed($tighter, $schemas), $schemas)))->toBe([
        'breaking schema.refinement-narrowed shape.oneOf.0.allOf.1.properties.radius.minimum',
        'breaking schema.refinement-narrowed shape.oneOf.1.allOf.1.properties.label.maxLength',
    ]);
});

it('compares as written where the two sides are not one union refined', function (array $old, array $new) use ($member, $components, $document, $changes): void {
    $schemas = $components(['Round' => $member('round', []), 'Square' => $member('square', [])], ['Lone' => ['type' => 'object']]);

    // Each pair below says something different on each side, so the reading must decline and the change
    // be found as it is written.
    expect($changes($document($old, $schemas), $document($new, $schemas)))->not->toBe([]);
})->with(function (): array {
    $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name];
    $pin = static fn (string $kind, array $more = []): array => ['properties' => ['kind' => ['const' => $kind]] + $more];
    $bounded = ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'maximum' => 3]]];
    $listed = ['oneOf' => [$ref('Round'), ['allOf' => [$ref('Square'), $bounded]]], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['round' => '#/components/schemas/Round', 'square' => '#/components/schemas/Square']]];
    $beside = ['$ref' => '#/components/schemas/Shape', 'anyOf' => [$pin('round'), $pin('square', ['n' => ['maximum' => 3]])]];

    return [
        'the discriminator gone' => [$beside, array_diff_key($listed, ['discriminator' => true])],
        'a member the union does not list' => [$beside, ['oneOf' => [...$listed['oneOf'], $ref('Lone')]] + $listed],
        'a branch that names no member' => [$beside, ['oneOf' => [$listed['oneOf'][1], ['type' => 'object']]] + $listed],
        'one member listed twice' => [$beside, ['oneOf' => [$listed['oneOf'][1], $listed['oneOf'][1]]] + $listed],
        'a refinement pinning no member' => [['$ref' => '#/components/schemas/Shape', 'anyOf' => [$pin('round'), $pin('oval')]], $listed],
        'two refinements of one member' => [['$ref' => '#/components/schemas/Shape', 'anyOf' => [$pin('round'), $pin('round')]], $listed],
        'fewer refinements than members' => [['$ref' => '#/components/schemas/Shape', 'anyOf' => [$pin('square', ['n' => ['maximum' => 3]])]], $listed],
        'a pointer to no union' => [['$ref' => '#/components/schemas/Lone', 'anyOf' => [$pin('round'), $pin('square')]], $listed],
        'a pointer to nothing registered' => [['$ref' => '#/components/schemas/Missing', 'anyOf' => [$pin('round'), $pin('square')]], $listed],
        'something else beside the pointer' => [$beside + ['maxProperties' => 4], $listed],
    ];
});

it('sets aside only what the member says at the same place', function (array $schema, array $member, mixed $left): void {
    $refs = ComponentRefs::of(UirDocument::fromArray([
        'openapi' => '3.1.1',
        'info' => ['title' => 'Shapes', 'version' => '1'],
        'paths' => [],
        'components' => ['schemas' => ['Point' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']], 'required' => ['x']]]],
    ]));

    expect(RefinedUnion::reduced($schema, $member, $refs))->toBe($left);
})->with([
    // `additionalProperties` reads the `properties` beside it, so nothing beside it is redundant.
    'a schema whose members read their neighbours' => [
        ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']], 'additionalProperties' => false],
        ['$ref' => '#/components/schemas/Point'],
        ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']], 'additionalProperties' => false],
    ],
    'a required member the member requires too' => [['required' => ['x', 'y']], ['$ref' => '#/components/schemas/Point'], ['required' => ['y']]],
    'items stated alike' => [['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2], ['type' => 'array', 'items' => ['type' => 'string']], ['maxItems' => 2]],
    'items bounded further' => [['items' => ['type' => 'string', 'maxLength' => 2]], ['type' => 'array', 'items' => ['type' => 'string']], ['items' => ['maxLength' => 2]]],
    'two values of one keyword' => [
        ['allOf' => [['type' => 'string', 'pattern' => '^a'], ['type' => 'string', 'pattern' => '^b']]],
        ['type' => 'string', 'pattern' => '^c'],
        ['allOf' => [['pattern' => '^a'], ['pattern' => '^b']]],
    ],
    'the member itself' => [['$ref' => '#/components/schemas/Point'], ['$ref' => '#/components/schemas/Point'], []],
    'a member nothing states' => [['minimum' => 1], [], ['minimum' => 1]],
]);
