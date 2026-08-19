<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Refs;

it('follows a local reference to the node it names, and reports where that node lives', function (): void {
    $document = ['components' => ['schemas' => ['Invoice' => ['type' => 'object']]]];

    expect(Refs::follow($document, ['$ref' => '#/components/schemas/Invoice'], ['x']))
        ->toBe([['type' => 'object'], ['components', 'schemas', 'Invoice']]);
});

it('unescapes a pointer segment on the way', function (): void {
    $document = ['paths' => ['/api/a~b' => ['get' => ['ok' => true]]]];

    expect(Refs::follow($document, ['$ref' => '#/paths/~1api~1a~0b/get'], ['x']))
        ->toBe([['ok' => true], ['paths', '/api/a~b', 'get']]);
});

it('follows a chain of references', function (): void {
    $document = [
        'a' => ['$ref' => '#/b'],
        'b' => ['type' => 'string'],
    ];

    expect(Refs::follow($document, ['$ref' => '#/a'], ['x'])[0])->toBe(['type' => 'string']);
});

it('leaves the node alone rather than crashing when the reference goes nowhere', function (mixed $ref): void {
    $document = ['components' => ['schemas' => ['Invoice' => ['type' => 'object']]]];
    $node = ['$ref' => $ref];

    expect(Refs::follow($document, $node, ['x']))->toBe([$node, ['x']]);
})->with([
    'a component nobody defined' => ['#/components/schemas/Ghost'],
    'a pointer into a scalar' => ['#/components/schemas/Invoice/type/nested'],
    'an external reference' => ['other.json#/Invoice'],
    'a bare fragment' => ['#'],
    'not a string at all' => [42],
]);

it('stops following a cycle rather than recursing forever', function (): void {
    $document = ['a' => ['$ref' => '#/b'], 'b' => ['$ref' => '#/a']];

    expect(Refs::follow($document, ['$ref' => '#/a'], ['x'])[0])->toHaveKey('$ref');
});

it('returns a node with no reference unchanged', function (): void {
    expect(Refs::follow([], ['type' => 'string'], ['x']))->toBe([['type' => 'string'], ['x']]);
});
