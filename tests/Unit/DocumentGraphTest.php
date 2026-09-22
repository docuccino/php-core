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
