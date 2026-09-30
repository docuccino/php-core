<?php

declare(strict_types=1);

use Docuccino\Core\Document\DocumentGraph;

/*
 * `without()` and the paths that reach nothing. Everything else in `DocumentGraph` is exercised through
 * the readers that walk real documents; a removal has branches no document-shaped test reaches — a key
 * that is not there, and a path that runs THROUGH something that is not a map — and each of them has to
 * leave the document alone rather than hydrate a shape to delete from it.
 */

it('removes one key path and nothing else', function (): void {
    $doc = ['paths' => ['/a' => ['get' => ['id' => 1], 'post' => ['id' => 2]], '/b' => ['get' => ['id' => 3]]]];

    expect(DocumentGraph::without($doc, ['paths', '/a', 'get']))->toBe([
        'paths' => ['/a' => ['post' => ['id' => 2]], '/b' => ['get' => ['id' => 3]]],
    ]);
});

it('leaves the parent behind, empty, for the caller to judge', function (): void {
    // Whether an emptied parent should go is a question about what that parent MEANS, so it is not this
    // operation's to answer — and the pruning caller is the one that knows.
    $doc = ['paths' => ['/a' => ['get' => ['id' => 1]]]];

    expect(DocumentGraph::without($doc, ['paths', '/a', 'get']))->toBe(['paths' => ['/a' => []]]);
});

it('removes nothing where the path leads nowhere', function (string $case, array $keys): void {
    $doc = ['paths' => ['/a' => ['get' => ['id' => 1]]], 'openapi' => '3.2.0'];

    expect(DocumentGraph::without($doc, $keys))->toBe($doc)->and($case)->not->toBe('');
})->with([
    'a key that is not there' => ['a key that is not there', ['paths', '/b', 'get']],
    'a path through a scalar' => ['a path through a scalar', ['openapi', 'version']],
    'no keys at all' => ['no keys at all', []],
]);

it('is safe to call twice', function (): void {
    $doc = ['paths' => ['/a' => ['get' => ['id' => 1]]]];
    $once = DocumentGraph::without($doc, ['paths', '/a', 'get']);

    expect(DocumentGraph::without($once, ['paths', '/a', 'get']))->toBe($once);
});

/*
 * Whether a node reaches an identity is a question about what it PUBLISHES. A pointer a value states —
 * an example, a default, a schema document an API serves — publishes nothing, and neither does the
 * provenance a node records about a shape a higher layer displaced. Reading either as reach makes a
 * scoped version change fork operations that never carried the shape, and expand a pointer an example
 * states into the shape it spells.
 */
$reachable = [
    'components' => ['schemas' => [
        'Tree' => ['x-docuccino' => ['id' => 'sch:v1:treetreetreetree'], 'type' => 'object'],
    ]],
];

it('reaches an identity through what a node publishes', function (array $node) use ($reachable): void {
    $reaches = DocumentGraph::componentsReaching($reachable, 'sch:v1:treetreetreetree');

    expect(DocumentGraph::nodeReaches($node, 'sch:v1:treetreetreetree', $reaches))->toBeTrue();
})->with([
    'a pointer at the component' => [['schema' => ['$ref' => '#/components/schemas/Tree']]],
    'a property named example' => [['schema' => ['properties' => ['example' => ['$ref' => '#/components/schemas/Tree']]]]],
    'the default response' => [['responses' => ['default' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Tree']]]]]]],
    'the identity itself' => [['schema' => ['x-docuccino' => ['id' => 'sch:v1:treetreetreetree'], 'type' => 'object']]],
]);

it('reaches nothing through a value a node states, or through the provenance it records', function (array $node) use ($reachable): void {
    $reaches = DocumentGraph::componentsReaching($reachable, 'sch:v1:treetreetreetree');

    expect(DocumentGraph::nodeReaches($node, 'sch:v1:treetreetreetree', $reaches))->toBeFalse();
})->with([
    'a media type\'s example' => [['content' => ['application/json' => ['schema' => ['type' => 'object'], 'example' => ['schema' => ['$ref' => '#/components/schemas/Tree']]]]]],
    'an Example Object\'s value' => [['content' => ['application/json' => ['examples' => ['one' => ['value' => ['$ref' => '#/components/schemas/Tree']]]]]]],
    'a schema\'s default' => [['schema' => ['type' => 'object', 'default' => ['$ref' => '#/components/schemas/Tree']]]],
    'an enum member' => [['schema' => ['enum' => [['$ref' => '#/components/schemas/Tree']]]]],
    'a displaced shape the provenance records' => [['schema' => ['type' => 'object', 'x-docuccino' => ['provenance' => [['producer' => 'attribute', 'layer' => 'attribute', 'fields' => ['properties'], 'overrode' => [['field' => 'properties', 'value' => ['tree' => ['$ref' => '#/components/schemas/Tree']], 'producer' => 'inference']]]]]]]],
]);

it('closes component reach over the pointers components publish, not the values they state', function (): void {
    $doc = [
        'components' => ['schemas' => [
            'Tree' => ['x-docuccino' => ['id' => 'sch:v1:treetreetreetree'], 'type' => 'object'],
            'Forest' => ['type' => 'object', 'properties' => ['trees' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tree']]]],
            'Catalogue' => ['type' => 'object', 'default' => ['schema' => ['$ref' => '#/components/schemas/Tree']]],
        ]],
    ];

    expect(DocumentGraph::componentsReaching($doc, 'sch:v1:treetreetreetree'))->toBe([
        '#/components/schemas/Tree' => true,
        '#/components/schemas/Forest' => true,
        '#/components/schemas/Catalogue' => false,
    ]);
});
