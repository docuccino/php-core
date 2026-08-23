<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\AttributedNode;
use Docuccino\Core\Tests\Fixtures\FullyHiddenNode;
use Docuccino\Core\Tests\Fixtures\HiddenPropertyNode;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * Table-driven coverage of the built-in {@see TypeToSchema}
 * chain: each closed DType kind maps to the expected JSON Schema fragment.
 */
function convertType(DType $type, StubTypeEngine $engine = new StubTypeEngine): array
{
    $converter = new SchemaConverter(DefaultTypeMappers::all(), $engine, new ComponentRegistry);

    return $converter->toSchema($type)->schema;
}

it('maps each scalar/literal/collection type to its schema', function (DType $type, array $expected): void {
    expect(convertType($type))->toBe($expected);
})->with([
    'string scalar' => [ScalarT::string(), ['type' => 'string']],
    'int scalar' => [ScalarT::int(), ['type' => 'integer']],
    'float scalar' => [ScalarT::float(), ['type' => 'number']],
    'bool scalar' => [ScalarT::bool(), ['type' => 'boolean']],
    'string literal' => [new LiteralT('draft'), ['type' => 'string', 'const' => 'draft']],
    'int literal' => [new LiteralT(15), ['type' => 'integer', 'const' => 15]],
    'list of int' => [new ListT(ScalarT::int()), ['type' => 'array', 'items' => ['type' => 'integer']]],
    'map of string' => [new MapT(ScalarT::string(), ScalarT::string()), ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
    'unknown → empty' => [new UnknownT('mixed'), []],
    'standalone null' => [new NullT, ['type' => 'null']],
    // An unresolved status marker degrades to a bare integer — no fabricated const/example.
    'status marker → bare integer' => [new StatusMarkerT, ['type' => 'integer']],
]);

it('maps an enum to a string enum of case names, hint-decorated by default', function (): void {
    expect(convertType(new EnumT('App\\Status', ['draft', 'published'])))
        ->toBe([
            'type' => 'string',
            'enum' => ['draft', 'published'],
            'x-enum-varnames' => ['draft', 'published'],
            'x-enumNames' => ['draft', 'published'],
        ]);
});

it('maps a keyed array shape to an object with required non-optional keys', function (): void {
    $shape = new ArrayShapeT([
        new ArrayShapeField('id', ScalarT::int()),
        new ArrayShapeField('name', ScalarT::string(), optional: true),
    ]);

    expect(convertType($shape))->toBe([
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
        ],
        'required' => ['id'],
    ]);
});

it('maps a nullable union to a type array', function (): void {
    expect(convertType(UnionT::of([ScalarT::string(), new NullT])))
        ->toBe(['type' => ['string', 'null']]);
});

it('maps a multi-member union to anyOf', function (): void {
    $schema = convertType(UnionT::of([ScalarT::string(), ScalarT::int()]));

    expect($schema)->toHaveKey('anyOf')
        ->and($schema['anyOf'])->toContain(['type' => 'string'])
        ->and($schema['anyOf'])->toContain(['type' => 'integer']);
});

it('hoists a class to a component and references it', function (): void {
    $engine = new StubTypeEngine(classes: [
        'App\\Data\\FormData' => new ClassMetadata('App\\Data\\FormData', [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', UnionT::of([ScalarT::string(), new NullT])),
        ]),
    ]);

    $registry = new ComponentRegistry;
    $converter = new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry);

    $result = $converter->toSchema(new ClassT('App\\Data\\FormData'));

    expect($result->schema)->toBe(['$ref' => '#/components/schemas/FormData']);
    expect($registry->schemas())->toHaveKey('FormData');
    expect($registry->schemas()['FormData'])->toBe([
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'title' => ['type' => ['string', 'null']],
        ],
        'required' => ['id'],
    ]);
    expect($registry->schemaIds()['FormData'])->toBe('App\\Data\\FormData');
});

it('breaks a self-reference cycle with a $ref to the same component', function (): void {
    $engine = new StubTypeEngine(classes: [
        'App\\Tree\\Node' => new ClassMetadata('App\\Tree\\Node', [
            new PropertyMetadata('parent', new ClassT('App\\Tree\\Node')),
            new PropertyMetadata('label', ScalarT::string()),
        ]),
    ]);

    $registry = new ComponentRegistry;
    $result = (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))
        ->toSchema(new ClassT('App\\Tree\\Node'));

    expect($result->schema)->toBe(['$ref' => '#/components/schemas/Node'])
        ->and($registry->schemas()['Node']['properties']['parent'])->toBe(['$ref' => '#/components/schemas/Node']);
});

it('points a self-reference at the suffixed name when the short name collides', function (): void {
    // Two distinct classes short to "Node"; the second is self-referential. Its cycle-breaking
    // $ref must target the suffixed component (Node_2) the registry hoists it under, not the
    // first class's "Node" — the registry, not the mapper, owns component naming.
    $engine = new StubTypeEngine(classes: [
        'App\\A\\Node' => new ClassMetadata('App\\A\\Node', [
            new PropertyMetadata('id', ScalarT::int()),
        ]),
        'App\\B\\Node' => new ClassMetadata('App\\B\\Node', [
            new PropertyMetadata('parent', new ClassT('App\\B\\Node')),
            new PropertyMetadata('label', ScalarT::string()),
        ]),
    ]);

    $registry = new ComponentRegistry;
    $converter = new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry);

    $converter->toSchema(new ClassT('App\\A\\Node'));
    $second = $converter->toSchema(new ClassT('App\\B\\Node'));

    expect($second->schema)->toBe(['$ref' => '#/components/schemas/Node_2'])
        ->and($registry->schemas())->toHaveKeys(['Node', 'Node_2'])
        ->and($registry->schemas()['Node_2']['properties']['parent'])->toBe(['$ref' => '#/components/schemas/Node_2'])
        ->and($registry->schemaIds()['Node_2'])->toBe('App\\B\\Node')
        // The suffix is provisional; the name each class is PUBLISHED under comes off the FQCNs.
        ->and($registry->schemaRenames())->toEqual(['Node' => 'ANode', 'Node_2' => 'BNode'])
        ->and($registry->nameCollisions()[0]->code)->toBe('components.name-collision');
});

it('honours #[SchemaName] and #[SchemaId] on a plain class the fallback mapper handles', function (): void {
    // A plain DTO reaches no integration mapper, so this is the only place its attributes can be read
    // — and the only escape hatch its author has when a short name collides with another namespace's.
    $fqcn = AttributedNode::class;
    $engine = new StubTypeEngine(classes: [
        $fqcn => new ClassMetadata($fqcn, [new PropertyMetadata('id', ScalarT::int())]),
    ]);

    $registry = new ComponentRegistry;
    $result = (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT($fqcn));

    expect($result->schema)->toBe(['$ref' => '#/components/schemas/RenamedNode'])
        ->and($registry->schemaIds())->toBe(['RenamedNode' => 'node.v1'])
        ->and($registry->diagnostics())->toBe([]);
});

it('honours #[Hidden] on a plain class the fallback mapper handles', function (): void {
    // A plain DTO reaches no integration mapper, so the fallback is the only place `#[Hidden]` can be
    // read — and the lint that tells authors to "Hide it (e.g. #[Hidden])" points them straight at it.
    // Both forms: the property's own attribute, and the class-level deny-list naming a property.
    $fqcn = HiddenPropertyNode::class;
    $engine = new StubTypeEngine(classes: [
        $fqcn => new ClassMetadata($fqcn, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('internal_score', ScalarT::string()),
            new PropertyMetadata('password_hash', ScalarT::string()),
        ]),
    ]);

    $registry = new ComponentRegistry;
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT($fqcn));

    expect($registry->schemas()['HiddenPropertyNode'])->toBe([
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required' => ['id'],
    ]);
});

it('degrades a class whose every property is hidden to a bare object', function (): void {
    // Nothing left to describe, so it takes the same route as an unexpandable class: a bare object and
    // no reserved component name, rather than an `object` with an empty properties map.
    $fqcn = FullyHiddenNode::class;
    $engine = new StubTypeEngine(classes: [
        $fqcn => new ClassMetadata($fqcn, [new PropertyMetadata('secret', ScalarT::string())]),
    ]);

    $registry = new ComponentRegistry;
    $result = (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT($fqcn));

    expect($result->schema)->toBe(['type' => 'object'])
        ->and($registry->schemas())->toBe([]);
});

it('falls back to the short name and the FQCN for a class carrying neither attribute', function (): void {
    // The unattributed contract the rename must not disturb.
    $engine = new StubTypeEngine(classes: [
        'App\\A\\Node' => new ClassMetadata('App\\A\\Node', [new PropertyMetadata('id', ScalarT::int())]),
    ]);

    $registry = new ComponentRegistry;
    $result = (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT('App\\A\\Node'));

    expect($result->schema)->toBe(['$ref' => '#/components/schemas/Node'])
        ->and($registry->schemaIds())->toBe(['Node' => 'App\\A\\Node']);
});

it('degrades an unexpandable class to a bare object at low confidence', function (): void {
    $result = (new SchemaConverter(DefaultTypeMappers::all(), new StubTypeEngine, new ComponentRegistry))
        ->toSchema(new ClassT('App\\Unknown'));

    expect($result->schema)->toBe(['type' => 'object'])
        ->and($result->confidence)->toBeLessThan(0.5);
});

it('lowers confidence when a type is unresolvable', function (): void {
    $converter = new SchemaConverter(DefaultTypeMappers::all(), new StubTypeEngine, new ComponentRegistry);

    expect($converter->toSchema(new UnknownT('mixed'))->confidence)->toBe(0.1)
        ->and($converter->toSchema(ScalarT::string())->confidence)->toBe(1.0);
});
