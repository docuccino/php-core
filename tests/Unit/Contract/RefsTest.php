<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Refs;

it('follows a local reference to the node it names, and reports where that node lives', function (): void {
    $document = ['components' => ['schemas' => ['Invoice' => ['type' => 'object']]]];

    expect(Refs::follow($document, ['$ref' => '#/components/schemas/Invoice'], ['x']))
        ->toBe([['type' => 'object'], ['components', 'schemas', 'Invoice'], null]);
});

it('unescapes a pointer segment on the way', function (): void {
    $document = ['paths' => ['/api/a~b' => ['get' => ['ok' => true]]]];

    expect(Refs::follow($document, ['$ref' => '#/paths/~1api~1a~0b/get'], ['x']))
        ->toBe([['ok' => true], ['paths', '/api/a~b', 'get'], null]);
});

it('follows a chain of references', function (): void {
    $document = [
        'a' => ['$ref' => '#/b'],
        'b' => ['type' => 'string'],
    ];

    expect(Refs::follow($document, ['$ref' => '#/a'], ['x']))->toBe([['type' => 'string'], ['b'], null]);
});

it('leaves the node alone rather than crashing when the reference goes nowhere', function (mixed $ref): void {
    $document = ['components' => ['schemas' => ['Invoice' => ['type' => 'object']]]];
    $node = ['$ref' => $ref];

    expect(Refs::follow($document, $node, ['x'])[0])->toBe($node)
        ->and(Refs::follow($document, $node, ['x'])[1])->toBe(['x']);
})->with([
    'a component nobody defined' => ['#/components/schemas/Ghost'],
    'a pointer into a scalar' => ['#/components/schemas/Invoice/type/nested'],
    'an external reference' => ['other.json#/Invoice'],
    'a bare fragment' => ['#'],
    'not a string at all' => [42],
]);

it('reports the reference that went nowhere rather than only degrading to it', function (mixed $ref, ?string $dangling): void {
    $document = ['components' => ['schemas' => ['Invoice' => ['type' => 'object']]]];

    expect(Refs::follow($document, ['$ref' => $ref], ['x'])[2])->toBe($dangling);
})->with([
    // A local pointer at a name nothing defines is a broken document, and the caller has to be able to
    // say so: reading `required` off a node that is only a pointer answers "not required".
    'a component nobody defined' => ['#/components/schemas/Ghost', '#/components/schemas/Ghost'],
    'a pointer into a scalar' => ['#/components/schemas/Invoice/type/nested', '#/components/schemas/Invoice/type/nested'],
    // Neither of these is a local reference this resolves at all, so there is nothing to report as
    // broken — the node is simply itself.
    'an external reference' => ['other.json#/Invoice', null],
    'a bare fragment' => ['#', null],
    'not a string at all' => [42, null],
]);

it('stops following a cycle rather than recursing forever, and reports it as unresolved', function (): void {
    $document = ['a' => ['$ref' => '#/b'], 'b' => ['$ref' => '#/a']];

    expect(Refs::follow($document, ['$ref' => '#/a'], ['x'])[0])->toHaveKey('$ref')
        ->and(Refs::follow($document, ['$ref' => '#/a'], ['x'])[2])->toBeString();
});

it('returns a node with no reference unchanged', function (): void {
    expect(Refs::follow([], ['type' => 'string'], ['x']))->toBe([['type' => 'string'], ['x'], null]);
});

/**
 * `member()` answers null to "no such member" and to "a member no reader can follow" alike, and the
 * two are different facts about the document — one promises nothing, the other promises something
 * nobody could check. Every shape a member can hold is a row, so a reader that starts calling a
 * written-but-unreadable member absent fails here rather than passing a check in silence.
 */
it('tells a member nobody wrote from one written in a shape it cannot follow', function (mixed $value, bool $malformed, bool $followed): void {
    $node = $value === 'ABSENT' ? [] : ['requestBody' => $value];

    expect(Refs::malformed($node, 'requestBody'))->toBe($malformed)
        ->and(Refs::member([], $node, 'requestBody', ['x']) !== null)->toBe($followed);
})->with([
    'an object' => [['content' => []], false, true],
    'an empty object, which is how associative decoding spells {}' => [[], false, true],
    'no member at all' => ['ABSENT', false, false],
    'a string' => ['a body, honest', true, false],
    'a number' => [42, true, false],
    // Written as null is still written, and it is still nothing a reader can follow.
    'an explicit null' => [null, true, false],
    'a boolean' => [true, true, false],
]);
