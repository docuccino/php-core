<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\DiscriminatedUnion;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\Tagged\Circle;
use Docuccino\Core\Tests\Fixtures\Tagged\Figure;
use Docuccino\Core\Tests\Fixtures\Tagged\Leaf;
use Docuccino\Core\Tests\Fixtures\Tagged\Line;
use Docuccino\Core\Tests\Fixtures\Tagged\MisSealed;
use Docuccino\Core\Tests\Fixtures\Tagged\Node;
use Docuccino\Core\Tests\Fixtures\Tagged\OpenShape;
use Docuccino\Core\Tests\Fixtures\Tagged\Point;
use Docuccino\Core\Tests\Fixtures\Tagged\Polygon;
use Docuccino\Core\Tests\Fixtures\Tagged\Shape;
use Docuccino\Core\Tests\Fixtures\Tagged\Square;
use Docuccino\Core\Tests\Fixtures\Tagged\Tree;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/*
 * A union whose members one pinned property tells apart is a `oneOf` with a `discriminator`. That is
 * what OpenAPI means by the keywords: `oneOf` asserts exactly one member matches, which only a required
 * property with a distinct value per member guarantees, and the mapping is what lets a generated client
 * pick the member from that value instead of trying each in turn. Anything short of it stays the
 * `anyOf` it was — true, and wider.
 *
 * The stub engine hands over the property types the engine recovers (a fixed value arrives as a
 * literal); the half that recovers them is tested against real classes in the engine package.
 */

/**
 * Convert a type with the given property types per class, returning the schema, the components and
 * the diagnostic codes raised.
 *
 * @param  array<string, array<string, DType>>  $classes  FQCN → property name → type
 * @return array{schema: array<string, mixed>, components: array<string, mixed>, diagnostics: list<string>, messages: list<string>}
 */
function taggedConversion(DType $type, array $classes): array
{
    return taggedConversions([$type], $classes);
}

/**
 * Convert several types into one build, in the order given, and settle the finished document the way
 * the assembler does, returning the last type's schema.
 *
 * @param  list<DType>  $types
 * @param  array<string, array<string, DType>>  $classes  FQCN → property name → type
 * @return array{schema: array<string, mixed>, components: array<string, mixed>, diagnostics: list<string>, messages: list<string>}
 */
function taggedConversions(array $types, array $classes): array
{
    $metadata = [];
    foreach ($classes as $fqcn => $properties) {
        $metadata[$fqcn] = new ClassMetadata($fqcn, array_map(
            static fn (string $name, DType $type): PropertyMetadata => new PropertyMetadata($name, $type),
            array_keys($properties),
            array_values($properties),
        ));
    }

    $components = new ComponentRegistry;
    $converter = new SchemaConverter(DefaultTypeMappers::all(), new StubTypeEngine(classes: $metadata), $components);
    $schemas = [];
    foreach ($types as $i => $type) {
        $schemas['/'.$i] = $converter->toSchema($type)->schema;
    }

    [$doc, $settled] = DiscriminatedUnion::settle(['paths' => $schemas, 'components' => ['schemas' => $components->schemas()]]);
    $diagnostics = [...$components->diagnostics(), ...$settled];

    return [
        'schema' => $doc['paths']['/'.(count($types) - 1)],
        'components' => $doc['components']['schemas'],
        'diagnostics' => array_map(static fn (object $d): string => $d->code, $diagnostics),
        'messages' => array_map(static fn (object $d): string => $d->message, $diagnostics),
    ];
}

/** @return array<string, array<string, DType>> */
function taggedShapes(DType $circleKind = new LiteralT('circle'), DType $squareKind = new LiteralT('square')): array
{
    return [
        Circle::class => ['kind' => $circleKind, 'radius' => ScalarT::int()],
        Square::class => ['kind' => $squareKind, 'side' => ScalarT::int()],
    ];
}

it('publishes a union of tagged classes as a oneOf discriminated by the tag', function (): void {
    $result = taggedConversion(UnionT::of([new ClassT(Circle::class), new ClassT(Square::class)]), taggedShapes());

    expect($result['schema'])->toBe([
        'oneOf' => [
            ['$ref' => '#/components/schemas/Circle'],
            ['$ref' => '#/components/schemas/Square'],
        ],
        'discriminator' => [
            'propertyName' => 'kind',
            'mapping' => [
                'circle' => '#/components/schemas/Circle',
                'square' => '#/components/schemas/Square',
            ],
        ],
    ])
        // The members are what make it exclusive: each requires the tag and pins it.
        ->and($result['components']['Circle']['properties']['kind'])->toBe(['type' => 'string', 'const' => 'circle'])
        ->and($result['components']['Circle']['required'])->toContain('kind')
        ->and($result['diagnostics'])->toBe([]);
});

it('answers the same whichever order the members were written in', function (): void {
    $forward = taggedConversion(new UnionT([new ClassT(Circle::class), new ClassT(Square::class)]), taggedShapes());
    $reverse = taggedConversion(new UnionT([new ClassT(Square::class), new ClassT(Circle::class)]), taggedShapes());

    expect($reverse['schema']['discriminator']['propertyName'])->toBe($forward['schema']['discriminator']['propertyName'])
        ->and($reverse['schema']['discriminator']['mapping'])->toEqualCanonicalizing($forward['schema']['discriminator']['mapping']);
});

it('keeps the null a nullable tagged union admits outside the tagged oneOf', function (): void {
    $result = taggedConversion(UnionT::of([new ClassT(Circle::class), new ClassT(Square::class), new NullT]), taggedShapes());

    // A discriminator dispatches on a property EVERY option of its oneOf carries, so a `null` option
    // inside it is one no client can reach by tag — a generator building a tagged union from the
    // discriminator (Zod's discriminatedUnion, openapi-generator's polymorphic models) has no member to
    // put it in. Null is still a value the server sends, so it stays, one level out, where `anyOf` says
    // "either the tagged object or null" and the oneOf under it holds only objects carrying the tag.
    expect($result['schema'])->toBe([
        'anyOf' => [
            [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/Circle'],
                    ['$ref' => '#/components/schemas/Square'],
                ],
                'discriminator' => [
                    'propertyName' => 'kind',
                    'mapping' => [
                        'circle' => '#/components/schemas/Circle',
                        'square' => '#/components/schemas/Square',
                    ],
                ],
            ],
            ['type' => 'null'],
        ],
    ]);
});

it('reads a one-value enum as a pinned tag just as it reads a const', function (): void {
    // What another producer may publish for the same fact: the reading is of the body, not of who built it.
    $body = static fn (string $kind): array => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => [$kind]]], 'required' => ['kind']];

    [$doc] = DiscriminatedUnion::settle([
        'paths' => ['/x' => ['anyOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']]]],
        'components' => ['schemas' => ['Circle' => $body('circle'), 'Square' => $body('square')]],
    ]);

    expect($doc['paths']['/x']['discriminator'])
        ->toBe(['propertyName' => 'kind', 'mapping' => ['circle' => '#/components/schemas/Circle', 'square' => '#/components/schemas/Square']]);
});

it('discriminates a recursive sealed hierarchy the same whichever class a build meets first', function (array $order): void {
    // Node holds a Tree, so converting Node first builds Tree while Node is still being expanded. A
    // decision taken then would read no Node body, and the component registry keeps the first body it is
    // given — so the published Tree would depend on which route happened to come first.
    $classes = [
        Node::class => ['kind' => new LiteralT('node'), 'child' => new ClassT(Tree::class)],
        Leaf::class => ['kind' => new LiteralT('leaf'), 'value' => ScalarT::int()],
    ];

    $result = taggedConversions(array_map(static fn (string $fqcn): ClassT => new ClassT($fqcn), $order), $classes);

    expect($result['components']['Tree'])->toBe([
        'oneOf' => [
            ['$ref' => '#/components/schemas/Leaf'],
            ['$ref' => '#/components/schemas/Node'],
        ],
        'discriminator' => [
            'propertyName' => 'kind',
            'mapping' => [
                'leaf' => '#/components/schemas/Leaf',
                'node' => '#/components/schemas/Node',
            ],
        ],
    ])
        ->and($result['diagnostics'])->toBe([]);
})->with([
    'the sealed parent first' => [[Tree::class, Node::class]],
    'the member that refers back to it first' => [[Node::class, Tree::class]],
    'the member alone' => [[Node::class]],
]);

it('discriminates a union written inside one of its own members', function (): void {
    // The inline union in Node's own body is built while Node is mid-expansion, on every build.
    $result = taggedConversion(new ClassT(Node::class), [
        Node::class => ['kind' => new LiteralT('node'), 'child' => UnionT::of([new ClassT(Node::class), new ClassT(Leaf::class), new NullT])],
        Leaf::class => ['kind' => new LiteralT('leaf'), 'value' => ScalarT::int()],
    ]);

    expect($result['components']['Node']['properties']['child']['anyOf'][0]['discriminator']['mapping'])
        ->toBe(['leaf' => '#/components/schemas/Leaf', 'node' => '#/components/schemas/Node'])
        ->and($result['components']['Node']['properties']['child']['anyOf'][1])->toBe(['type' => 'null']);
});

it('repoints the provenance of the anyOf it discriminates', function (): void {
    [$doc] = DiscriminatedUnion::settle([
        'paths' => ['/x' => [
            'x-docuccino' => ['provenance' => [['producer' => 'inference', 'layer' => 'inference', 'fields' => ['anyOf']]]],
            'anyOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']],
        ]],
        'components' => ['schemas' => [
            'Circle' => ['type' => 'object', 'properties' => ['kind' => ['const' => 'circle']], 'required' => ['kind']],
            'Square' => ['type' => 'object', 'properties' => ['kind' => ['const' => 'square']], 'required' => ['kind']],
        ]],
    ]);

    expect($doc['paths']['/x']['x-docuccino']['provenance'][0]['fields'])->toBe(['discriminator', 'oneOf'])
        ->and($doc['paths']['/x'])->not->toHaveKey('anyOf');
});

it('leaves a union-shaped value alone', function (string $key): void {
    // An example, a default or a const is data the API sends, not a schema: rewriting it would change
    // what the document says the server returns.
    $value = ['anyOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']]];
    $tagged = static fn (string $kind): array => ['type' => 'object', 'properties' => ['kind' => ['const' => $kind]], 'required' => ['kind']];

    [$doc] = DiscriminatedUnion::settle([
        'paths' => ['/x' => ['type' => 'object', $key => $value]],
        'components' => ['schemas' => ['Circle' => $tagged('circle'), 'Square' => $tagged('square')]],
    ]);

    expect($doc['paths']['/x'][$key])->toBe($value);
})->with(['x-docuccino', 'x-examples', 'example', 'examples', 'default', 'const', 'enum']);

it('reads a union the same whatever the name of the key holding it', function (array $path): void {
    // A property, a response code or a header is a NAME, whatever it is called: one named `default` or
    // `x-y` holds a schema like any other, and the union in it is owed the same form it gets under
    // `other`. Only a keyword position makes `default`, `enum` or an `x-` member data.
    $union = ['anyOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']]];
    $tagged = static fn (string $kind): array => ['type' => 'object', 'properties' => ['kind' => ['const' => $kind]], 'required' => ['kind']];

    $doc = ['components' => ['schemas' => ['Circle' => $tagged('circle'), 'Square' => $tagged('square')]]];
    $at = &$doc;
    foreach ($path as $segment) {
        $at = &$at[$segment];
    }
    $at = $union;
    unset($at);

    [$settled] = DiscriminatedUnion::settle($doc);
    $read = $settled;
    foreach ($path as $segment) {
        $read = $read[$segment];
    }

    expect($read)->toHaveKey('discriminator')->and($read)->not->toHaveKey('anyOf');
})->with([
    'a property named other' => [['components', 'schemas', 'Holder', 'properties', 'other']],
    'a property named default' => [['components', 'schemas', 'Holder', 'properties', 'default']],
    'a property named enum' => [['components', 'schemas', 'Holder', 'properties', 'enum']],
    'a property named const' => [['components', 'schemas', 'Holder', 'properties', 'const']],
    'a property named example' => [['components', 'schemas', 'Holder', 'properties', 'example']],
    'a property named examples' => [['components', 'schemas', 'Holder', 'properties', 'examples']],
    'a property named value' => [['components', 'schemas', 'Holder', 'properties', 'value']],
    'a property named x-y' => [['components', 'schemas', 'Holder', 'properties', 'x-y']],
    'a pattern property named default' => [['components', 'schemas', 'Holder', 'patternProperties', 'default']],
    'a component named enum' => [['components', 'schemas', 'enum']],
    'the default response' => [['paths', '/x', 'get', 'responses', 'default', 'content', 'application/json', 'schema']],
    'a numbered response' => [['paths', '/x', 'get', 'responses', '200', 'content', 'application/json', 'schema']],
    'a header named x-kind' => [['paths', '/x', 'get', 'responses', '200', 'headers', 'x-kind', 'schema']],
]);

it('leaves a union-shaped value alone wherever the document holds data', function (array $path): void {
    // The other half of the same grammar: under a keyword that holds data — an Example Object's value, a
    // response map's own `x-` extension — a union-shaped value is what the API sends, not a schema.
    $union = ['anyOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']]];
    $tagged = static fn (string $kind): array => ['type' => 'object', 'properties' => ['kind' => ['const' => $kind]], 'required' => ['kind']];

    $doc = ['components' => ['schemas' => ['Circle' => $tagged('circle'), 'Square' => $tagged('square')]]];
    $at = &$doc;
    foreach ($path as $segment) {
        $at = &$at[$segment];
    }
    $at = $union;
    unset($at);

    [$settled] = DiscriminatedUnion::settle($doc);
    $read = $settled;
    foreach ($path as $segment) {
        $read = $read[$segment];
    }

    expect($read)->toBe($union);
})->with([
    'an example object value' => [['paths', '/x', 'get', 'responses', '200', 'content', 'application/json', 'examples', 'one', 'value']],
    'an example object dataValue' => [['components', 'examples', 'one', 'dataValue']],
    'an extension on the responses map' => [['paths', '/x', 'get', 'responses', 'x-note']],
    'an extension on the paths map' => [['paths', 'x-note']],
]);

it('stays an anyOf and says why when one member leaves the tag open', function (): void {
    $result = taggedConversion(UnionT::of([new ClassT(Circle::class), new ClassT(Square::class), new ClassT(Polygon::class)]), [
        ...taggedShapes(),
        Polygon::class => ['kind' => ScalarT::string(), 'sides' => ScalarT::int()],
    ]);

    // `oneOf` would be a claim of exclusivity nothing proves: a Polygon may say `circle`.
    expect($result['schema'])->toHaveKey('anyOf')
        ->and($result['schema'])->not->toHaveKey('discriminator')
        ->and($result['diagnostics'])->toBe(['components.union-undiscriminated'])
        ->and($result['messages'][0])->toContain('`kind`')
        ->and($result['messages'][0])->toContain('Polygon leaves it open');
});

it('stays an anyOf and says why when two members share a value', function (): void {
    $result = taggedConversion(UnionT::of([new ClassT(Circle::class), new ClassT(Square::class)]), taggedShapes(squareKind: new LiteralT('circle')));

    expect($result['schema'])->toHaveKey('anyOf')
        ->and($result['diagnostics'])->toBe(['components.union-undiscriminated'])
        ->and($result['messages'][0])->toContain("share the value 'circle'");
});

it('says nothing about a union nobody tagged', function (array $classes): void {
    $result = taggedConversion(UnionT::of([new ClassT(Circle::class), new ClassT(Square::class)]), $classes);

    // Two shapes that merely share a property name, or pin nothing at all, were never written to be told
    // apart: a diagnostic here would fire on every ordinary union and teach the reader to skip the channel.
    expect($result['schema'])->toHaveKey('anyOf')
        ->and($result['diagnostics'])->toBe([]);
})->with([
    'no property in common' => [[Circle::class => ['radius' => ScalarT::int()], Square::class => ['side' => ScalarT::int()]]],
    'a shared property nobody pins' => [taggedShapes(ScalarT::string(), ScalarT::string())],
    'a shared property only one member pins' => [taggedShapes(squareKind: ScalarT::string())],
]);

it('picks the discriminator by name when more than one property qualifies', function (): void {
    $result = taggedConversion(UnionT::of([new ClassT(Circle::class), new ClassT(Square::class)]), [
        Circle::class => ['type' => new LiteralT('round'), 'kind' => new LiteralT('circle')],
        Square::class => ['type' => new LiteralT('angular'), 'kind' => new LiteralT('square')],
    ]);

    expect($result['schema']['discriminator']['propertyName'])->toBe('kind');
});

it('leaves a union without names for a mapping as an anyOf', function (string $case, UnionT $union): void {
    $result = taggedConversion($union, taggedShapes());

    expect($result['schema'])->toHaveKey('anyOf')
        ->and($result['schema'])->not->toHaveKey('discriminator');
})->with([
    // An inline shape has no component a mapping could point at.
    'inline shapes' => ['inline', UnionT::of([
        new ArrayShapeT([new ArrayShapeField('kind', new LiteralT('circle'))]),
        new ArrayShapeT([new ArrayShapeField('kind', new LiteralT('square'))]),
    ])],
    'a class beside a scalar' => ['scalar', UnionT::of([new ClassT(Circle::class), ScalarT::string()])],
]);

it('leaves out a tag PHP would read back as an integer key', function (): void {
    // A mapping keyed `"1"`, `"2"` is an array to PHP and would publish as a JSON list, not an object.
    $result = taggedConversion(UnionT::of([new ClassT(Circle::class), new ClassT(Square::class)]), taggedShapes(new LiteralT('1'), new LiteralT('2')));

    expect($result['schema'])->toHaveKey('anyOf');
});

it('publishes a value typed by a sealed interface as the union of what it permits, under its own name', function (): void {
    $result = taggedConversion(new ListT(new ClassT(Shape::class)), taggedShapes());

    expect($result['schema'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']])
        ->and($result['components']['Shape']['oneOf'])->toBe([
            ['$ref' => '#/components/schemas/Circle'],
            ['$ref' => '#/components/schemas/Square'],
        ])
        ->and($result['components']['Shape']['discriminator']['mapping'])->toBe([
            'circle' => '#/components/schemas/Circle',
            'square' => '#/components/schemas/Square',
        ])
        // Polygon implements Shape too; the seal is what the author declared, and it does not name it.
        ->and($result['components'])->not->toHaveKey('Polygon');
});

it('reads the Psalm spelling of the seal on an abstract class', function (): void {
    $result = taggedConversion(new ClassT(Figure::class), [Line::class => ['label' => ScalarT::string()], Point::class => ['label' => ScalarT::string()]]);

    expect($result['components']['Figure'])->toBe(['anyOf' => [
        ['$ref' => '#/components/schemas/Line'],
        ['$ref' => '#/components/schemas/Point'],
    ]]);
});

it('keeps an open interface a bare object, and says nothing', function (): void {
    // With no seal, the classes a value may be are not a set anything declares — a scan for implementors
    // would publish a closed union over an open hierarchy.
    $result = taggedConversion(new ClassT(OpenShape::class), []);

    expect($result['schema'])->toBe(['type' => 'object'])
        ->and($result['diagnostics'])->toBe([]);
});

it('degrades a seal naming what is not its subtype, and names it', function (): void {
    $result = taggedConversion(new ClassT(MisSealed::class), taggedShapes());

    expect($result['schema'])->toBe(['type' => 'object'])
        ->and($result['diagnostics'])->toBe(['docblock.sealed-unreadable'])
        ->and($result['messages'][0])->toContain(Circle::class)
        ->and($result['messages'][0])->toContain(Polygon::class)
        ->and($result['messages'][0])->toContain('Docuccino\\Core\\Tests\\Fixtures\\Tagged\\Missing');
});

it('repoints a discriminator mapping when the component it names is renamed', function (): void {
    // A mapping value is a reference written as a plain string, so a walk that follows only `$ref`
    // would leave it naming the slot the component no longer occupies.
    $renamed = ComponentNames::rename([
        'oneOf' => [['$ref' => '#/components/schemas/Circle_2']],
        'discriminator' => ['propertyName' => 'kind', 'mapping' => ['circle' => '#/components/schemas/Circle_2', 'other' => 'Elsewhere']],
    ], ['Circle_2' => 'Tagged.Circle']);

    expect($renamed['oneOf'][0]['$ref'])->toBe('#/components/schemas/Tagged.Circle')
        ->and($renamed['discriminator']['mapping'])->toBe(['circle' => '#/components/schemas/Tagged.Circle', 'other' => 'Elsewhere']);
});

it('reads an enum-typed tag that is not fixed as open', function (): void {
    // The declared enum admits every case, so a member typed by it could hold any other member's value.
    $result = taggedConversion(UnionT::of([new ClassT(Circle::class), new ClassT(Square::class)]), taggedShapes(squareKind: new EnumT('Some\\Kind', ['Circle', 'Square'])));

    expect($result['schema'])->toHaveKey('anyOf');
});

it('keeps an empty object beside the tagged oneOf, as it keeps null', function (array $outside): void {
    // Every tagged member requires its tag, so none of them admits `{}`: the empty object is a value no
    // member can reach, exactly as null is, and it stays one level out rather than making the oneOf false.
    $body = static fn (string $kind): array => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'const' => $kind]], 'required' => ['kind']];

    [$doc] = DiscriminatedUnion::settle([
        'paths' => ['/x' => ['anyOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square'], ...$outside]]],
        'components' => ['schemas' => ['Circle' => $body('circle'), 'Square' => $body('square')]],
    ]);

    expect($doc['paths']['/x']['anyOf'][0]['discriminator']['propertyName'])->toBe('kind')
        ->and(array_slice($doc['paths']['/x']['anyOf'], 1))->toBe([DiscriminatedUnion::EMPTY_OBJECT, ...array_slice($outside, 1)]);
})->with([
    'the empty object alone' => [[['type' => 'object', 'maxProperties' => 0]]],
    'written in the other key order' => [[['maxProperties' => 0, 'type' => 'object']]],
    'the empty object and null' => [[['type' => 'object', 'maxProperties' => 0], ['type' => 'null']]],
]);

it('discriminates nothing beside an object that is not empty', function (): void {
    // An object bounded to one member may be a tagged member's own value, so the members are no longer
    // provably the only way a value is matched; the union stays the anyOf it was.
    $body = static fn (string $kind): array => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'const' => $kind]], 'required' => ['kind']];
    $union = ['anyOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square'], ['type' => 'object', 'maxProperties' => 1]]];

    [$doc] = DiscriminatedUnion::settle([
        'paths' => ['/x' => $union],
        'components' => ['schemas' => ['Circle' => $body('circle'), 'Square' => $body('square')]],
    ]);

    expect($doc['paths']['/x'])->toBe($union);
});
