<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * The shared-error hoist collapses an error body repeated across operations into one
 * `components.responses` entry, which is most of a large document's bytes. What it must not lose is
 * identity: each operation keeps its own response id and provenance beside the `$ref`, so the id-based
 * semantic diff still sees one response per operation. Deliberately narrow — 4xx/5xx, bodies that repeat,
 * bodies that exist.
 */
function errorDoc(array $responsesByPath, array $representation = []): array
{
    $paths = [];
    foreach ($responsesByPath as $path => $responses) {
        $paths[$path] = ['get' => ['responses' => $responses]];
    }

    return transformedErrorDoc(['paths' => $paths], $representation);
}

/** The transformer run over a whole document, for the shapes `errorDoc()`'s one-GET-per-path form can't build. */
function transformedErrorDoc(array $doc, array $representation = []): array
{
    $draft = new UirDocumentDraft($doc);
    (new SharedErrorResponses)->transform(
        $draft,
        new DocumentContext(new DocumentConfig('default', [], representation: $representation), 'doc:default'),
    );

    return $draft->toArray();
}

/** A `{message}` body, optionally carrying provenance for the identity assertions. */
function messageBody(string $description = 'Not Found', ?string $producer = null): array
{
    $response = [
        'description' => $description,
        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]]],
    ];

    return $producer === null
        ? $response
        : ['x-docuccino' => ['id' => 'res:v1:'.$producer, 'provenance' => [['producer' => $producer]]]] + $response;
}

/** The same `{code, hint}` error body, differing only in the example a given route documents it with. */
function examplableBody(mixed $example): array
{
    return [
        'description' => 'Forbidden',
        'content' => ['application/problem+json' => [
            'schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'hint' => ['type' => 'string']]],
            'example' => $example,
        ]],
    ];
}

/** The example a given hoisted 403 component carries, or null when it has none. */
function hoisted403Example(array $responsesByPath, string $component = 'Error403'): mixed
{
    $media = errorDoc($responsesByPath)['components']['responses'][$component]['content']['application/problem+json'] ?? [];

    return $media['example'] ?? null;
}

it('hoists a body repeated across operations and points each operation at it', function (): void {
    $doc = errorDoc([
        '/a' => ['404' => messageBody()],
        '/b' => ['404' => messageBody()],
    ]);

    expect($doc['paths']['/a']['get']['responses']['404'])->toBe(['$ref' => '#/components/responses/Error404'])
        ->and($doc['paths']['/b']['get']['responses']['404'])->toBe(['$ref' => '#/components/responses/Error404'])
        ->and($doc['components']['responses']['Error404'])->toBe(messageBody());
});

it('keeps each operation\'s own id and provenance beside the reference', function (): void {
    // The semantic diff is id-based, so a hoist that dropped these would silently change what a
    // changeset can see.
    $doc = errorDoc([
        '/a' => ['404' => messageBody('Not Found', 'integration:framework-errors')],
        '/b' => ['404' => messageBody('Not Found', 'integration:inferred-handler')],
    ]);

    $a = $doc['paths']['/a']['get']['responses']['404'];
    $b = $doc['paths']['/b']['get']['responses']['404'];

    expect($a['x-docuccino']['provenance'][0]['producer'])->toBe('integration:framework-errors')
        ->and($b['x-docuccino']['provenance'][0]['producer'])->toBe('integration:inferred-handler')
        ->and($a['x-docuccino']['id'])->not->toBe($b['x-docuccino']['id'])
        ->and($a['$ref'])->toBe($b['$ref'])
        // Provenance is a per-route fact, so the shared component states none of it.
        ->and($doc['components']['responses']['Error404'])->not->toHaveKey('x-docuccino');
});

it('leaves alone what is not worth sharing', function (string $case, array $responsesByPath): void {
    $doc = errorDoc($responsesByPath);

    expect($doc['components']['responses'] ?? [])->toBe([])
        ->and($doc['paths'])->toBe(errorDoc($responsesByPath, ['errors' => ['components' => false]])['paths']);
})->with([
    // One occurrence is already as small as it gets, and a $ref would only add indirection.
    ['a body used once', ['/a' => ['404' => messageBody()]]],
    // A description-only response costs nothing to inline and reads better that way.
    ['a repeated response with no body', ['/a' => ['404' => ['description' => 'Not Found']], '/b' => ['404' => ['description' => 'Not Found']]]],
    // Success bodies are not this transformer's business.
    ['a repeated 200', ['/a' => ['200' => messageBody('OK')], '/b' => ['200' => messageBody('OK')]]],
    // The Problem Details preset's own hoists are already shared.
    ['responses that are already references', ['/a' => ['404' => ['$ref' => '#/components/responses/ProblemNotFound']], '/b' => ['404' => ['$ref' => '#/components/responses/ProblemNotFound']]]],
]);

it('never merges two different shapes under one name', function (): void {
    $other = ['description' => 'Unprocessable Entity', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];

    $doc = errorDoc([
        '/a' => ['422' => messageBody('Unprocessable Entity')],
        '/b' => ['422' => messageBody('Unprocessable Entity')],
        '/c' => ['422' => $other],
        '/d' => ['422' => $other],
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error422', 'Error422_2'])
        ->and($doc['paths']['/a']['get']['responses']['422']['$ref'])->toBe('#/components/responses/Error422')
        ->and($doc['paths']['/c']['get']['responses']['422']['$ref'])->toBe('#/components/responses/Error422_2')
        ->and($doc['components']['responses']['Error422_2'])->toBe($other);
});

it('collapses bodies that differ only in key order', function (): void {
    $one = ['description' => 'Gone', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'title' => 'Gone']]]];
    $two = ['content' => ['application/json' => ['schema' => ['title' => 'Gone', 'type' => 'object']]], 'description' => 'Gone'];

    $doc = errorDoc(['/a' => ['410' => $one], '/b' => ['410' => $two]]);

    expect($doc['components']['responses'])->toHaveCount(1)
        ->and($doc['paths']['/a']['get']['responses']['410']['$ref'])->toBe($doc['paths']['/b']['get']['responses']['410']['$ref']);
});

it('inlines everything when the knob is off', function (): void {
    $responses = ['/a' => ['404' => messageBody()], '/b' => ['404' => messageBody()]];

    $doc = errorDoc($responses, ['errors' => ['components' => false]]);

    expect($doc)->not->toHaveKey('components')
        ->and($doc['paths']['/a']['get']['responses']['404'])->toBe(messageBody());
});

it('gives each example its own component rather than reconciling them into one', function (): void {
    // The example is part of the identity. Keeping ONE component per shape would mean documenting only what
    // every referring route says identically, and on a real 159-route app that trade goes the wrong way: 196
    // of 199 403s fold a true `type` value, 3 cannot, and intersecting erases a correct value from 98.5% of
    // the document to avoid over-claiming for 1.5%. The measured cost of grouping by example there is about
    // two components per busy status, not one per route.
    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin'])],
        '/c' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'renew your token'])],
        '/d' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'renew your token'])],
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error403', 'Error403_2'])
        ->and($doc['paths']['/a']['get']['responses']['403']['$ref'])->toBe('#/components/responses/Error403')
        ->and($doc['paths']['/b']['get']['responses']['403']['$ref'])->toBe('#/components/responses/Error403')
        ->and($doc['paths']['/c']['get']['responses']['403']['$ref'])->toBe('#/components/responses/Error403_2')
        // Each component keeps the whole example it can honestly claim, `hint` included.
        ->and($doc['components']['responses']['Error403'])->toBe(examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin']))
        ->and($doc['components']['responses']['Error403_2'])->toBe(examplableBody(['code' => 'forbidden', 'hint' => 'renew your token']));
});

it('leaves a lone dissenting route inline instead of blunting the majority', function (): void {
    // The route that folded nothing is the 1.5% case. It keeps its own honest body inline; the routes that
    // did fold a value keep saying so.
    $folded = examplableBody(['code' => 'forbidden', 'detail' => 'You may not do that.']);
    $generic = examplableBody(['code' => 'forbidden']);

    $doc = errorDoc([
        '/a' => ['403' => $folded],
        '/b' => ['403' => $folded],
        '/c' => ['403' => $folded],
        '/d' => ['403' => $generic],
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error403'])
        ->and($doc['components']['responses']['Error403'])->toBe($folded)
        ->and($doc['paths']['/d']['get']['responses']['403'])->toBe($generic);
});

it('keeps an example every route states identically', function (): void {
    $body = examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin']);

    expect(errorDoc(['/a' => ['403' => $body], '/b' => ['403' => $body]])['components']['responses']['Error403'])
        ->toBe($body);
});

it('does not hoist bodies that differ only in their example', function (string $case, array $responsesByPath): void {
    // Two bodies with different examples are two bodies, so neither repeats and there is nothing to share.
    $doc = errorDoc($responsesByPath);

    expect($doc['components']['responses'] ?? [])->toBe([])
        ->and($doc['paths'])->toBe(errorDoc($responsesByPath, ['errors' => ['components' => false]])['paths']);
})->with(function (): array {
    $noExample = examplableBody([]);
    unset($noExample['content']['application/problem+json']['example']);

    return [
        ['every member differs', [
            '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
            '/b' => ['403' => examplableBody(['code' => 'denied'])],
        ]],
        // Having no example is its own claim, and not one the route with an example makes.
        ['one route documents none', [
            '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
            '/b' => ['403' => $noExample],
        ]],
        ['two different scalar examples', [
            '/a' => ['403' => examplableBody('forbidden')],
            '/b' => ['403' => examplableBody('denied')],
        ]],
    ];
});

it('keeps a scalar example both routes state identically', function (): void {
    expect(hoisted403Example([
        '/a' => ['403' => examplableBody('forbidden')],
        '/b' => ['403' => examplableBody('forbidden')],
    ]))->toBe('forbidden');
});

it('names each example group in document order', function (): void {
    // Which group claims the unsuffixed name is the first one the document mentions, so a rebuild of the
    // same code always spells the same $refs.
    $one = ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin'])];
    $two = ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'renew your token'])];

    expect(hoisted403Example(['/a' => $one, '/b' => $one, '/c' => $two, '/d' => $two]))
        ->toBe(['code' => 'forbidden', 'hint' => 'ask an admin'])
        ->and(hoisted403Example(['/a' => $two, '/b' => $two, '/c' => $one, '/d' => $one]))
        ->toBe(['code' => 'forbidden', 'hint' => 'renew your token']);
});

it('leaves a document with nothing to walk exactly as it found it', function (string $case, array $doc): void {
    // An overlay or a hand-written document can put anything anywhere; the transformer walks past what it
    // cannot read rather than assuming a shape.
    expect(transformedErrorDoc($doc))->toBe($doc);
})->with([
    ['no paths at all', ['openapi' => '3.2.0']],
    ['paths that are not a map', ['paths' => 'nonsense']],
    ['a path item that is not a map', ['paths' => ['/a' => 'nonsense', '/b' => 'nonsense']]],
    ['an operation that is not a map', ['paths' => ['/a' => ['get' => 'nonsense']]]],
    ['responses that are not a map', ['paths' => ['/a' => ['get' => ['responses' => 'nonsense']]]]],
]);

it('hoists the readable operations of a document that also contains junk', function (): void {
    $doc = transformedErrorDoc(['paths' => [
        '/a' => ['get' => ['responses' => ['404' => messageBody()]], 'post' => 'nonsense'],
        '/b' => 'nonsense',
        '/c' => ['get' => ['responses' => ['404' => messageBody()]], 'put' => ['responses' => 'nonsense']],
    ]]);

    expect($doc['paths']['/a']['get']['responses']['404'])->toBe(['$ref' => '#/components/responses/Error404'])
        ->and($doc['paths']['/c']['get']['responses']['404'])->toBe(['$ref' => '#/components/responses/Error404'])
        ->and($doc['paths']['/b'])->toBe('nonsense')
        ->and($doc['components']['responses']['Error404'])->toBe(messageBody());
});

it('reuses a component a previous build already registered', function (): void {
    // Re-running over an emitted document (a restored snapshot, say) must not duplicate the entry it
    // already made into `Error404_2`, so an unchanged document stays byte-identical.
    $doc = ['paths' => [
        '/a' => ['get' => ['responses' => ['404' => ['$ref' => '#/components/responses/Error404']]]],
        '/b' => ['get' => ['responses' => ['404' => messageBody()]]],
        '/c' => ['get' => ['responses' => ['404' => messageBody()]]],
    ], 'components' => ['responses' => ['Error404' => messageBody()]]];

    expect(transformedErrorDoc($doc)['components']['responses'])->toBe(['Error404' => messageBody()]);
});

it('is deterministic, including the name a colliding shape gets', function (): void {
    $responses = [
        '/a' => ['404' => messageBody()],
        '/b' => ['404' => messageBody()],
        '/c' => ['404' => ['description' => 'Not Found', 'content' => ['application/problem+json' => ['schema' => ['type' => 'object']]]]],
        '/d' => ['404' => ['description' => 'Not Found', 'content' => ['application/problem+json' => ['schema' => ['type' => 'object']]]]],
    ];

    expect(json_encode(errorDoc($responses)))->toBe(json_encode(errorDoc($responses)));
});
