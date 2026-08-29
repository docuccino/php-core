<?php

declare(strict_types=1);

use Docuccino\Core\Document\RenamedFieldExamples;
use Docuccino\Core\Draft\SchemaKeywords;

/*
 * The shape-guided half of a version rename: an example is rewritten only where the schema governing
 * that position says the object standing there is the renamed one, and dropped wherever the walk cannot
 * say so with certainty. Publishing no example is valid; publishing one the schema rejects is the defect
 * the rename would otherwise introduce.
 */

const RENAMED_FIELD_ID = 'sch:v1:renamed';

/**
 * A document publishing one component schema — the renamed one — and whatever else a case needs.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function renamedDocument(array $extra = []): array
{
    return array_replace_recursive([
        'components' => [
            'schemas' => [
                'Form' => [
                    'x-docuccino' => ['id' => RENAMED_FIELD_ID],
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ], $extra);
}

/**
 * @param  array<string, mixed>  $doc
 * @return array{0: array<string, mixed>, 1: list<string>}
 */
function renameFields(array $doc): array
{
    return RenamedFieldExamples::inDocument($doc, RENAMED_FIELD_ID, 'name', 'title');
}

it('rewrites a media type example over the renamed component', function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Form']],
            'example' => [['id' => 1, 'title' => 'Onboarding']],
        ]]]]]]],
    ]));

    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json']['example'])
        ->toBe([['id' => 1, 'name' => 'Onboarding']])
        ->and($dropped)->toBe([]);
});

it('rewrites a request body example and a parameter example', function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['post' => [
            'parameters' => [[
                'name' => 'form',
                'in' => 'query',
                'schema' => ['$ref' => '#/components/schemas/Form'],
                'example' => ['id' => 1, 'title' => 'Onboarding'],
            ]],
            'requestBody' => ['content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/Form'],
                'example' => ['id' => 2, 'title' => 'Offboarding'],
            ]]],
        ]]],
    ]));

    $operation = $doc['paths']['/forms']['post'];

    expect($operation['parameters'][0]['example'])->toBe(['id' => 1, 'name' => 'Onboarding'])
        ->and($operation['requestBody']['content']['application/json']['example'])->toBe(['id' => 2, 'name' => 'Offboarding'])
        ->and($dropped)->toBe([]);
});

it("rewrites a schema's own example and its list of instances", function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'components' => ['schemas' => ['Form' => [
            'example' => ['id' => 1, 'title' => 'Onboarding'],
            'examples' => [['id' => 2, 'title' => 'Offboarding']],
        ]]],
    ]));

    expect($doc['components']['schemas']['Form']['example'])->toBe(['id' => 1, 'name' => 'Onboarding'])
        ->and($doc['components']['schemas']['Form']['examples'])->toBe([['id' => 2, 'name' => 'Offboarding']])
        ->and($dropped)->toBe([]);
});

it('rewrites the renamed schema wherever a map or a tuple position puts it', function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => [
                'type' => 'object',
                'properties' => ['rows' => [
                    'type' => 'array',
                    'prefixItems' => [['$ref' => '#/components/schemas/Form']],
                    'items' => ['$ref' => '#/components/schemas/Form'],
                ]],
                'additionalProperties' => ['$ref' => '#/components/schemas/Form'],
            ],
            'example' => [
                'rows' => [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']],
                'featured' => ['id' => 3, 'title' => 'C'],
            ],
        ]]]]]]],
    ]));

    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json']['example'])->toBe([
        'rows' => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']],
        'featured' => ['id' => 3, 'name' => 'C'],
    ])->and($dropped)->toBe([]);
});

it('leaves a like-named property on an unrelated schema alone', function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'components' => ['schemas' => ['Widget' => [
            'x-docuccino' => ['id' => 'sch:v1:other'],
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
        ]]],
        'paths' => ['/widgets' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/Widget'],
            'example' => ['title' => 'Sprocket'],
        ]]]]]]],
    ]));

    expect($doc['paths']['/widgets']['get']['responses']['200']['content']['application/json']['example'])
        ->toBe(['title' => 'Sprocket'])
        ->and($dropped)->toBe([]);
});

it('drops an example the schema does not settle on one shape for', function (array $schema, mixed $example): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => $schema,
            'example' => $example,
        ]]]]]]],
    ]));

    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json'])
        ->not->toHaveKey('example')
        ->and($dropped)->toBe(['/paths/~1forms/get/responses/200/content/application~1json/example']);
})->with([
    'a oneOf branch that resolves to no one shape' => [
        ['oneOf' => [['$ref' => '#/components/schemas/Form'], ['type' => 'string']]],
        ['id' => 1, 'title' => 'Onboarding'],
    ],
    'an anyOf branch that resolves to no one shape' => [
        ['anyOf' => [['$ref' => '#/components/schemas/Form'], ['type' => 'string']]],
        ['id' => 1, 'title' => 'Onboarding'],
    ],
    'a value of a kind the schema does not describe' => [
        ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Form']],
        ['id' => 1, 'title' => 'Onboarding'],
    ],
    'an example already carrying both names' => [
        ['$ref' => '#/components/schemas/Form'],
        ['id' => 1, 'name' => 'Old', 'title' => 'New'],
    ],
    'a list where the renamed object itself is expected' => [
        ['$ref' => '#/components/schemas/Form'],
        [['id' => 1, 'title' => 'Onboarding']],
    ],
]);

it('leaves an example under a reference the document does not define', function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['type' => 'object', 'properties' => ['form' => ['$ref' => '#/components/schemas/Gone']]],
            'example' => ['form' => ['id' => 1, 'title' => 'Onboarding']],
        ]]]]]]],
    ]));

    // A pointer at nothing states nothing, so it never says the renamed schema is under it. Dropping on
    // one would take out every example beneath a typo, for a rename that may have nothing to do with it
    // — and the document already owes the reader `lint.unresolved-reference` about the pointer itself.
    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json']['example'])
        ->toBe(['form' => ['id' => 1, 'title' => 'Onboarding']])
        ->and($dropped)->toBe([]);
});

it('drops an example over a schema that contains itself', function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'components' => ['schemas' => ['Form' => ['properties' => ['parent' => ['$ref' => '#/components/schemas/Form']]]]],
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/Form'],
            'example' => ['id' => 1, 'title' => 'Onboarding', 'parent' => ['id' => 2, 'title' => 'Root']],
        ]]]]]]],
    ]));

    // The cycle is only a problem where the value keeps going down it; the drop is the honest answer to
    // a walk that would otherwise have to guess how deep the example goes.
    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json'])
        ->toHaveKey('example')
        ->and($doc['paths']['/forms']['get']['responses']['200']['content']['application/json']['example'])
        ->toBe(['id' => 1, 'name' => 'Onboarding', 'parent' => ['id' => 2, 'name' => 'Root']])
        ->and($dropped)->toBe([]);
});

it('leaves an unsettled example that never names the field that moved', function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['oneOf' => [['$ref' => '#/components/schemas/Form'], ['type' => 'string']]],
            'example' => ['id' => 1, 'slug' => 'onboarding'],
        ]]]]]]],
    ]));

    // Nothing was owed here: the walk could not settle, and there was no rewrite to settle about.
    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json']['example'])
        ->toBe(['id' => 1, 'slug' => 'onboarding'])
        ->and($dropped)->toBe([]);
});

it('drops one entry of a named examples map and keeps the rest', function (): void {
    [$doc, $dropped] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/Form'],
            'examples' => [
                'good' => ['summary' => 'Fine', 'value' => ['id' => 1, 'title' => 'Onboarding']],
                'both' => ['value' => ['id' => 2, 'name' => 'Old', 'title' => 'New']],
                'elsewhere' => ['externalValue' => 'https://example.test/form.json'],
            ],
        ]]]]]]],
    ]));

    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json']['examples'])->toBe([
        'good' => ['summary' => 'Fine', 'value' => ['id' => 1, 'name' => 'Onboarding']],
        'elsewhere' => ['externalValue' => 'https://example.test/form.json'],
    ])->and($dropped)->toBe(['/paths/~1forms/get/responses/200/content/application~1json/examples/both/value']);
});

it('drops the whole member when every entry of a map goes', function (): void {
    [$doc] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/Form'],
            'examples' => ['both' => ['value' => ['name' => 'Old', 'title' => 'New']]],
        ]]]]]]],
    ]));

    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json'])->not->toHaveKey('examples');
});

/*
 * The undecidable set is DERIVED from the schema model rather than listed, so a keyword nobody taught
 * this to read degrades to a drop instead of being walked past. Both halves are pinned: which keywords
 * the derivation names, and that each one really does stop the walk.
 */
it('treats every subschema keyword it does not read as undecidable', function (): void {
    $positioned = [];
    foreach ([
        SchemaKeywords::POSITION_SCHEMA,
        SchemaKeywords::POSITION_SCHEMA_MAP,
        SchemaKeywords::POSITION_SCHEMA_LIST,
        SchemaKeywords::POSITION_STRING_LIST_MAP,
    ] as $position) {
        $positioned = [...$positioned, ...SchemaKeywords::at($position)];
    }

    // Stated here rather than read off the class: the keywords the walk resolves, and the two stores a
    // `$ref` points INTO rather than applying to anything. Everything else the model knows about has to
    // be undecidable.
    $read = [
        'allOf',
        'properties',
        'patternProperties',
        'additionalProperties',
        'items',
        'prefixItems',
        'additionalItems',
        '$defs',
        'definitions',
    ];

    expect(count($positioned))->toBeGreaterThan(15)
        ->and(RenamedFieldExamples::undecidable())->toEqualCanonicalizing(array_values(array_diff($positioned, $read)));
});

it('drops an example where an undecidable keyword stands over the renamed schema', function (string $keyword): void {
    $reaching = ['$ref' => '#/components/schemas/Form'];

    $branch = match (true) {
        in_array($keyword, SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_MAP), true) => ['x' => $reaching],
        in_array($keyword, SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST), true) => [$reaching],
        default => $reaching,
    };

    [$doc, $dropped] = renameFields(renamedDocument([
        'paths' => ['/forms' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], $keyword => $branch],
            'example' => ['id' => 1, 'title' => 'Onboarding'],
        ]]]]]]],
    ]));

    expect($doc['paths']['/forms']['get']['responses']['200']['content']['application/json'])->not->toHaveKey('example')
        ->and($dropped)->toHaveCount(1);
})->with(RenamedFieldExamples::undecidable());
