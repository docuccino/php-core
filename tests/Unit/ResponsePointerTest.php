<?php

declare(strict_types=1);

use Docuccino\Core\Document\ResponsePointer;

/**
 * Whether a workflow output reads something its operation's response documents.
 *
 * A pure function over two arrays, so it is unit-tested rather than built: the feature tests prove it
 * reaches the document, and these prove what it actually decides. Most of the file is the SILENT half —
 * the class may only contradict a pointer when the schema positively describes the shape, and every
 * reason it stays quiet is a separate clause that a report would turn into a warning where nothing is
 * wrong.
 */

/**
 * An operation documenting one 200 whose JSON body is `$schema`.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function operationReturning(array $schema): array
{
    return ['responses' => ['200' => ['content' => ['application/json' => ['schema' => $schema]]]]];
}

it('finds a member the response documents', function (): void {
    $operation = operationReturning(['properties' => ['id' => ['type' => 'integer']]]);

    expect(ResponsePointer::undocumented($operation, [], '$response.body#/id'))->toBeNull();
});

it('names the pointer the response does not document', function (): void {
    $operation = operationReturning(['properties' => ['id' => ['type' => 'integer']]]);

    expect(ResponsePointer::undocumented($operation, [], '$response.body#/nope'))->toBe('/nope');
});

it('stays silent for every reason it cannot refute a pointer', function (string $case, array $operation): void {
    expect(ResponsePointer::undocumented($operation, [], '$response.body#/anything'))->toBeNull($case);
})->with([
    'no responses at all' => ['no responses at all', []],
    'no success response' => ['no success response', ['responses' => ['404' => ['description' => 'Gone']]]],
    // Two successes is the operation saying it has more than one outcome; nothing here can say which
    // one an output is read from.
    'two success responses' => ['two success responses', ['responses' => [
        '200' => ['content' => ['application/json' => ['schema' => ['properties' => ['id' => []]]]]],
        '201' => ['content' => ['application/json' => ['schema' => ['properties' => ['id' => []]]]]],
    ]]],
    'no JSON body' => ['no JSON body', ['responses' => ['200' => ['content' => ['text/csv' => ['schema' => ['properties' => ['id' => []]]]]]]]],
    'no schema at all' => ['no schema at all', ['responses' => ['200' => ['description' => 'OK']]]],
    'an empty schema' => ['an empty schema', [...operationReturning([])]],
    'an object naming no properties' => ['an object naming no properties', [...operationReturning(['type' => 'object'])]],
]);

it('stays silent for an expression it does not judge', function (string $expression): void {
    $operation = operationReturning(['properties' => ['id' => []]]);

    expect(ResponsePointer::undocumented($operation, [], $expression))->toBeNull();
})->with([
    '$response.header.Location',
    '$steps.reserve.outputs.holdId',
    '$inputs.basketId',
    '$statusCode',
]);

it('walks an index through the items a list documents', function (): void {
    $operation = operationReturning([
        'type' => 'object',
        'properties' => ['data' => ['type' => 'array', 'items' => ['properties' => ['id' => []]]]],
    ]);

    expect(ResponsePointer::undocumented($operation, [], '$response.body#/data/0/id'))->toBeNull()
        ->and(ResponsePointer::undocumented($operation, [], '$response.body#/data/0/nope'))->toBe('/data/0/nope');
});

it('says nothing about an index into a list with no items', function (): void {
    $operation = operationReturning(['properties' => ['data' => ['type' => 'array']]]);

    expect(ResponsePointer::undocumented($operation, [], '$response.body#/data/0/id'))->toBeNull();
});

it('unescapes a pointer segment', function (): void {
    $operation = operationReturning(['properties' => ['a/b' => ['properties' => ['c~d' => []]]]]);

    expect(ResponsePointer::undocumented($operation, [], '$response.body#/a~1b/c~0d'))->toBeNull();
});

it('follows a $ref into the components the document publishes', function (): void {
    $doc = ['components' => ['schemas' => ['Form' => ['properties' => ['id' => []]]]]];
    $operation = operationReturning(['$ref' => '#/components/schemas/Form']);

    expect(ResponsePointer::undocumented($operation, $doc, '$response.body#/id'))->toBeNull()
        ->and(ResponsePointer::undocumented($operation, $doc, '$response.body#/nope'))->toBe('/nope');
});

/*
 * Composition. One branch documenting the member is enough, and an exhausted branch set refutes only
 * where nothing else on the node can speak — a node that composes AND names properties of its own is
 * the ordinary `allOf` idiom, and reporting there would warn about a member documented on the very
 * same node.
 */
it('accepts a member any one branch documents', function (string $keyword): void {
    $operation = operationReturning([$keyword => [
        ['properties' => ['other' => []]],
        ['properties' => ['id' => []]],
    ]]);

    expect(ResponsePointer::undocumented($operation, [], '$response.body#/id'))->toBeNull();
})->with(['allOf', 'anyOf', 'oneOf']);

it('refuses a member no branch documents', function (): void {
    $operation = operationReturning(['oneOf' => [
        ['properties' => ['a' => []]],
        ['properties' => ['b' => []]],
    ]]);

    expect(ResponsePointer::undocumented($operation, [], '$response.body#/c'))->toBe('/c');
});

it('reads the properties a composing node names beside its branches', function (): void {
    // The false report this had: the branch set failed and the walk returned before ever looking at the
    // `properties` on the same node.
    $doc = ['components' => ['schemas' => ['Base' => ['properties' => ['id' => []]]]]];
    $operation = operationReturning([
        'allOf' => [['$ref' => '#/components/schemas/Base']],
        'properties' => ['extra' => ['type' => 'string']],
    ]);

    expect(ResponsePointer::undocumented($operation, $doc, '$response.body#/extra'))->toBeNull()
        ->and(ResponsePointer::undocumented($operation, $doc, '$response.body#/id'))->toBeNull();
});

/*
 * The guards, EXECUTED. A depth bound is not a work bound: a branch keyword recurses on the same
 * pointer, so a `$ref` graph that fans out re-enters the same subtree once per path through it. Both
 * rows below finish in milliseconds with the memo and neither does without it — the acyclic one is the
 * one that used to hang, which is the opposite of the usual intuition about cycles.
 */
it('answers a self-referential schema instead of recursing forever', function (): void {
    $doc = ['components' => ['schemas' => ['Loop' => ['anyOf' => [['$ref' => '#/components/schemas/Loop']]]]]];
    $operation = operationReturning(['$ref' => '#/components/schemas/Loop']);

    $started = microtime(true);
    $answer = ResponsePointer::undocumented($operation, $doc, '$response.body#/absent');

    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and($answer)->toBeNull();
});

it('answers a fanned-out schema graph in bounded work', function (): void {
    // 31 components, each composing two references to the one below it: 2^31 paths, and one visit per
    // (node, remaining pointer) once the work is bounded.
    $schemas = ['A0' => ['type' => 'object', 'properties' => ['known' => []]]];

    for ($i = 1; $i <= 30; $i++) {
        $schemas['A'.$i] = ['oneOf' => [
            ['$ref' => '#/components/schemas/A'.($i - 1)],
            ['$ref' => '#/components/schemas/A'.($i - 1)],
        ]];
    }

    $doc = ['components' => ['schemas' => $schemas]];
    $operation = operationReturning(['$ref' => '#/components/schemas/A30']);

    $started = microtime(true);
    $answer = ResponsePointer::undocumented($operation, $doc, '$response.body#/absent');

    // The pointer really is absent, so this is also the answer the check exists to give — reached in
    // bounded time rather than never.
    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and($answer)->toBe('/absent');
});
