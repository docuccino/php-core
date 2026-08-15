<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\Emitter;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * The shared-error hoist collapses an error body SHAPE repeated across operations into one
 * `components.schemas` entry, which is most of a large document's bytes and — more to the point — the
 * difference between a generated client with one error type and one with an error type per operation.
 *
 * Two things it must not lose. Identity: each operation keeps its own response id and provenance, so the
 * id-based semantic diff still sees one response per operation. Messaging: `description`, `headers` and
 * the media type's own `example` stay on the operation, because an OAS Reference Object may carry none
 * of them beside a `$ref` — so a difference in how two operations illustrate one error never splits it
 * into two definitions. Deliberately narrow: 4xx/5xx, shapes that repeat, bodies that exist.
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

/** The `{message}` shape `messageBody()` states, as the hoisted component spells it. */
function messageShape(): array
{
    return ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]];
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

/** A path's error response as documented, read through `components.responses` when it was hoisted. */
function responseAt(array $doc, string $path, string $status): array
{
    $response = $doc['paths'][$path]['get']['responses'][$status] ?? [];
    if (! is_array($response)) {
        return [];
    }

    $ref = $response['$ref'] ?? null;
    if (! is_string($ref)) {
        return $response;
    }

    $component = $doc['components']['responses'][substr($ref, strlen('#/components/responses/'))] ?? [];

    return $response + (is_array($component) ? $component : []);
}

/** The `$ref` a path's error response points its schema at, or null when the shape stayed inline. */
function schemaRefAt(array $doc, string $path, string $status, string $mediaType = 'application/json'): ?string
{
    return responseAt($doc, $path, $status)['content'][$mediaType]['schema']['$ref'] ?? null;
}

/** The `$ref` a path's error response ITSELF is, or null when the response stayed on the operation. */
function responseRefAt(array $doc, string $path, string $status): ?string
{
    return $doc['paths'][$path]['get']['responses'][$status]['$ref'] ?? null;
}

/** The example a given path's error response keeps for itself. */
function exampleAt(array $doc, string $path, string $status, string $mediaType = 'application/problem+json'): mixed
{
    return responseAt($doc, $path, $status)['content'][$mediaType]['example'] ?? null;
}

it('hoists a shape repeated across operations and points each operation at it', function (): void {
    $doc = errorDoc([
        '/a' => ['404' => messageBody()],
        '/b' => ['404' => messageBody()],
    ]);

    expect(schemaRefAt($doc, '/a', '404'))->toBe('#/components/schemas/Error404')
        ->and(schemaRefAt($doc, '/b', '404'))->toBe('#/components/schemas/Error404')
        ->and($doc['components']['schemas'])->toHaveKey('Error404');

    $component = $doc['components']['schemas']['Error404'];
    unset($component['x-docuccino']);

    expect($component)->toBe(messageShape());
});

it('keeps the response object whole, wherever it ends up living', function (): void {
    // A Reference Object may carry only `$ref`, `summary` and `description`, so `description`, `headers`
    // and the media type's `example` can never sit beside a `$ref`. They travel INSIDE the shared
    // response when one is minted, and stay on the operation when one is not — never dropped either way.
    $body = messageBody('Not Found');
    $body['headers'] = ['X-Request-Id' => ['schema' => ['type' => 'string']]];
    $body['content']['application/json']['example'] = ['message' => 'No such form.'];

    $doc = errorDoc(['/a' => ['404' => $body], '/b' => ['404' => $body]]);
    $response = responseAt($doc, '/a', '404');

    expect($response)->toHaveKeys(['description', 'headers', 'content'])
        ->and($response['description'])->toBe('Not Found')
        ->and($response['headers'])->toBe(['X-Request-Id' => ['schema' => ['type' => 'string']]])
        ->and($response['content']['application/json']['example'])->toBe(['message' => 'No such form.'])
        // Both hoists fired: one shape, one response, and the response points at the shape.
        ->and(responseRefAt($doc, '/a', '404'))->toBe('#/components/responses/Error404')
        ->and(schemaRefAt($doc, '/a', '404'))->toBe('#/components/schemas/Error404');
});

it('leaves the response on the operation when only its shape is shared', function (): void {
    // Two operations that illustrate one error differently cannot share a response — so they keep their
    // own, complete with their own example, and share only the shape underneath.
    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/b' => ['403' => examplableBody(['code' => 'denied'])],
    ]);

    expect(responseRefAt($doc, '/a', '403'))->toBeNull()
        ->and($doc['components']['responses'] ?? null)->toBeNull()
        ->and($doc['paths']['/a']['get']['responses']['403'])->toHaveKeys(['description', 'content'])
        ->and(schemaRefAt($doc, '/a', '403', 'application/problem+json'))->toBe('#/components/schemas/Error403')
        ->and(exampleAt($doc, '/a', '403'))->toBe(['code' => 'forbidden'])
        ->and(exampleAt($doc, '/b', '403'))->toBe(['code' => 'denied']);
});

it('keeps each operation\'s own id and provenance beside the reference', function (): void {
    // The semantic diff is id-based, so a hoist that dropped these would silently change what a
    // changeset can see. The two responses differ only in provenance, which is not part of the identity,
    // so they share one component — and each operation still says who produced its own.
    $doc = errorDoc([
        '/a' => ['404' => messageBody('Not Found', 'integration:framework-errors')],
        '/b' => ['404' => messageBody('Not Found', 'integration:inferred-handler')],
    ]);

    $left = $doc['paths']['/a']['get']['responses']['404'];
    $right = $doc['paths']['/b']['get']['responses']['404'];

    expect($left['x-docuccino']['provenance'][0]['producer'])->toBe('integration:framework-errors')
        ->and($right['x-docuccino']['provenance'][0]['producer'])->toBe('integration:inferred-handler')
        ->and($left['x-docuccino']['id'])->not->toBe($right['x-docuccino']['id'])
        ->and($left['$ref'])->toBe($right['$ref'])
        // Provenance is a per-route fact, so neither shared component states any of it.
        ->and($doc['components']['responses']['Error404'])->not->toHaveKey('x-docuccino')
        ->and(json_encode($doc['components']))->not->toContain('provenance');
});

it('keeps a schema\'s own provenance beside its reference when the response stays inline', function (): void {
    // Where only the shape is shared, the operation keeps its whole response — so the per-route
    // provenance the schema carries has somewhere to live and is not thrown away.
    $a = examplableBody(['code' => 'forbidden']);
    $b = examplableBody(['code' => 'denied']);
    $a['content']['application/problem+json']['schema']['x-docuccino'] = ['provenance' => [['producer' => 'integration:framework-errors']]];
    $b['content']['application/problem+json']['schema']['x-docuccino'] = ['provenance' => [['producer' => 'integration:inferred-handler']]];

    $doc = errorDoc(['/a' => ['403' => $a], '/b' => ['403' => $b]]);

    $left = $doc['paths']['/a']['get']['responses']['403']['content']['application/problem+json']['schema'];
    $right = $doc['paths']['/b']['get']['responses']['403']['content']['application/problem+json']['schema'];

    expect($left)->toHaveKey('x-docuccino')
        ->and($left['x-docuccino']['provenance'][0]['producer'])->toBe('integration:framework-errors')
        ->and($right['x-docuccino']['provenance'][0]['producer'])->toBe('integration:inferred-handler')
        ->and($left['$ref'])->toBe($right['$ref'])
        ->and($doc['components']['schemas']['Error403'])->not->toHaveKey('provenance');
});

it('gives the hoisted shape a content-derived identity', function (): void {
    // Every other `components.schemas` entry carries one, and the diff pairs components by it — so a
    // component that renames still compares against the same shape rather than reading as add + remove.
    $doc = errorDoc(['/a' => ['404' => messageBody()], '/b' => ['404' => messageBody()]]);

    expect($doc['components']['schemas']['Error404'])->toHaveKey('x-docuccino')
        ->and($doc['components']['schemas']['Error404']['x-docuccino']['id'])->toStartWith('sch:v1:');
});

it('hoists no shape from what is not worth sharing', function (string $case, array $responsesByPath): void {
    // The SHAPE pass declining. Whether the response pass then shares the whole response is its own
    // question — these say only that nothing reached `components.schemas`.
    expect(errorDoc($responsesByPath)['components']['schemas'] ?? [])->toBe([]);
})->with([
    // One occurrence is already as small as it gets, and a $ref would only add indirection.
    ['a shape used once', ['/a' => ['404' => messageBody()]]],
    // A description-only response costs nothing to inline and reads better that way.
    ['a repeated response with no body', ['/a' => ['404' => ['description' => 'Not Found']], '/b' => ['404' => ['description' => 'Not Found']]]],
    // Success bodies are not this transformer's business.
    ['a repeated 200', ['/a' => ['200' => messageBody('OK')], '/b' => ['200' => messageBody('OK')]]],
    // The Problem Details preset's own hoists are already shared.
    ['responses that are already references', ['/a' => ['404' => ['$ref' => '#/components/responses/ProblemNotFound']], '/b' => ['404' => ['$ref' => '#/components/responses/ProblemNotFound']]]],
    // The component registry already hoisted this shape; pointing a pointer at a pointer buys nothing.
    ['schemas that are already references', [
        '/a' => ['404' => ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetails']]]]],
        '/b' => ['404' => ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetails']]]]],
    ]],
    // An empty schema asserts nothing, so there is nothing to name.
    ['an empty schema', [
        '/a' => ['404' => ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => []]]]],
        '/b' => ['404' => ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => []]]]],
    ]],
    // A media type with no schema at all states nothing this transformer can hoist.
    ['a media type with no schema', [
        '/a' => ['404' => ['description' => 'Not Found', 'content' => ['application/json' => ['example' => ['message' => 'a']]]]],
        '/b' => ['404' => ['description' => 'Not Found', 'content' => ['application/json' => ['example' => ['message' => 'b']]]]],
    ]],
]);

it('leaves the document exactly as it found it when neither pass can help', function (string $case, array $responsesByPath): void {
    $doc = errorDoc($responsesByPath);

    expect($doc['components'] ?? [])->toBe([])
        ->and($doc['paths'])->toBe(errorDoc($responsesByPath, ['errors' => ['components' => false]])['paths']);
})->with([
    // One occurrence is already as small as it gets, and a $ref would only add indirection.
    ['a body used once', ['/a' => ['404' => messageBody()]]],
    // A description-only response costs nothing to inline and reads better that way.
    ['a repeated response with no body', ['/a' => ['404' => ['description' => 'Not Found']], '/b' => ['404' => ['description' => 'Not Found']]]],
    // Success bodies are not this transformer's business.
    ['a repeated 200', ['/a' => ['200' => messageBody('OK')], '/b' => ['200' => messageBody('OK')]]],
    // Neither pass can share a body whose media type states an example and no shape.
    ['media types with no schema and differing examples', [
        '/a' => ['404' => ['description' => 'Not Found', 'content' => ['application/json' => ['example' => ['message' => 'a']]]]],
        '/b' => ['404' => ['description' => 'Not Found', 'content' => ['application/json' => ['example' => ['message' => 'b']]]]],
    ]],
]);

it('shares one shape however the operations choose to illustrate it', function (string $case, array $responsesByPath): void {
    // The whole point. A `description` or an `example` is how an operation PRESENTS an error, never what
    // the error IS, so two operations presenting one shape differently still state it once.
    $doc = errorDoc($responsesByPath);

    expect(array_keys($doc['components']['schemas']))->toBe(['Error403'])
        ->and(array_map(
            static fn (string $path): ?string => schemaRefAt($doc, $path, '403', 'application/problem+json'),
            array_keys($responsesByPath),
        ))->each->toBe('#/components/schemas/Error403');
})->with(function (): array {
    $noExample = examplableBody([]);
    unset($noExample['content']['application/problem+json']['example']);

    $described = examplableBody(['code' => 'forbidden']);
    $described['description'] = 'You may not do that';

    return [
        ['examples that differ in every member', [
            '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
            '/b' => ['403' => examplableBody(['code' => 'denied'])],
        ]],
        ['one route documenting no example at all', [
            '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
            '/b' => ['403' => $noExample],
        ]],
        ['two different scalar examples', [
            '/a' => ['403' => examplableBody('forbidden')],
            '/b' => ['403' => examplableBody('denied')],
        ]],
        ['a differing description', [
            '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
            '/b' => ['403' => $described],
        ]],
        ['four routes across two example groups', [
            '/a' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin'])],
            '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin'])],
            '/c' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'renew your token'])],
            '/d' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'renew your token'])],
        ]],
    ];
});

it('keeps every route\'s own messaging when it shares the shape', function (): void {
    // Sharing must not cost a route the thing it was saying. Each keeps the example it can honestly
    // claim, and the route that documents none still documents none.
    $noExample = examplableBody([]);
    unset($noExample['content']['application/problem+json']['example']);

    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'renew your token'])],
        '/c' => ['403' => $noExample],
    ]);

    expect(exampleAt($doc, '/a', '403'))->toBe(['code' => 'forbidden', 'hint' => 'ask an admin'])
        ->and(exampleAt($doc, '/b', '403'))->toBe(['code' => 'forbidden', 'hint' => 'renew your token'])
        ->and($doc['paths']['/c']['get']['responses']['403']['content']['application/problem+json'])
        ->not->toHaveKey('example');
});

it('retires the plain name rather than awarding it when two shapes contest a status', function (): void {
    // A first-come suffix hands `Error422` to whichever shape the walk meets first, so an unrelated
    // route swaps what it means. Neither gets it; each takes a name derived from its own content.
    $other = ['description' => 'Unprocessable Entity', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['errors' => ['type' => 'object']]]]]];

    $doc = errorDoc([
        '/a' => ['422' => messageBody('Unprocessable Entity')],
        '/b' => ['422' => messageBody('Unprocessable Entity')],
        '/c' => ['422' => $other],
        '/d' => ['422' => $other],
    ]);

    $names = array_keys($doc['components']['schemas']);

    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('Error422')
        ->and($names)->not->toContain('Error422_2')
        ->and($names)->each->toMatch('/^Error422_[a-z2-7]{8}$/')
        ->and(schemaRefAt($doc, '/a', '422'))->not->toBe(schemaRefAt($doc, '/c', '422'));
});

it('keeps the plain name for the one shape that holds a status alone', function (): void {
    // No contest, no churn: the common case must not pay a discriminator for the rare one.
    $doc = errorDoc([
        '/a' => ['404' => messageBody()],
        '/b' => ['404' => messageBody()],
        '/c' => ['422' => messageBody('Unprocessable Entity')],
        '/d' => ['422' => messageBody('Unprocessable Entity')],
    ]);

    expect(array_keys($doc['components']['schemas']))->toBe(['Error404', 'Error422']);
});

it('names a shape the same wherever the document meets it', function (): void {
    // Left to the walk, `Error403` means whichever shape sorts first, so adding a route ahead of the
    // others repoints operations that have not changed.
    $one = ['403' => messageBody('Forbidden')];
    $two = ['403' => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]]]]]];

    $first = errorDoc(['/a' => $one, '/b' => $one, '/c' => $two, '/d' => $two]);
    $second = errorDoc(['/a' => $two, '/b' => $two, '/c' => $one, '/d' => $one]);

    expect(schemaRefAt($first, '/a', '403'))->toBe(schemaRefAt($second, '/c', '403'))
        ->and(schemaRefAt($first, '/c', '403'))->toBe(schemaRefAt($second, '/a', '403'))
        ->and(array_keys($first['components']['schemas']))->toBe(array_keys($second['components']['schemas']));
});

it('does not move an existing component when an unrelated route is added', function (): void {
    $shape = ['403' => messageBody('Forbidden')];
    $other = ['403' => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]]]]]];
    $third = ['403' => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]]]]]];

    $before = errorDoc(['/b' => $shape, '/c' => $shape, '/d' => $other, '/e' => $other]);
    // A route that sorts FIRST, and a third shape arriving after the two already contesting the status.
    $after = errorDoc(['/a' => $shape, '/b' => $shape, '/c' => $shape, '/d' => $other, '/e' => $other, '/f' => $third, '/g' => $third]);

    expect(schemaRefAt($after, '/b', '403'))->toBe(schemaRefAt($before, '/b', '403'))
        ->and(schemaRefAt($after, '/d', '403'))->toBe(schemaRefAt($before, '/d', '403'))
        ->and($after['components']['schemas'])->toHaveCount(3);
});

it('gives a status its own name even where two statuses share a shape', function (): void {
    // Keyed on the status too, so editing what a 404 looks like can never repoint a 403.
    $doc = errorDoc([
        '/a' => ['403' => messageBody('Forbidden'), '404' => messageBody()],
        '/b' => ['403' => messageBody('Forbidden'), '404' => messageBody()],
    ]);

    expect(array_keys($doc['components']['schemas']))->toBe(['Error403', 'Error404'])
        ->and(schemaRefAt($doc, '/a', '403'))->toBe('#/components/schemas/Error403')
        ->and(schemaRefAt($doc, '/a', '404'))->toBe('#/components/schemas/Error404');
});

it('collapses shapes that differ only in key order', function (): void {
    $one = ['description' => 'Gone', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'title' => 'Gone']]]];
    $two = ['content' => ['application/json' => ['schema' => ['title' => 'Gone', 'type' => 'object']]], 'description' => 'Gone'];

    $doc = errorDoc(['/a' => ['410' => $one], '/b' => ['410' => $two]]);

    expect($doc['components']['schemas'])->toHaveCount(1)
        ->and(schemaRefAt($doc, '/a', '410'))->toBe(schemaRefAt($doc, '/b', '410'));
});

it('inlines everything when the knob is off', function (): void {
    $responses = ['/a' => ['404' => messageBody()], '/b' => ['404' => messageBody()]];

    $doc = errorDoc($responses, ['errors' => ['components' => false]]);

    expect($doc)->not->toHaveKey('components')
        ->and($doc['paths']['/a']['get']['responses']['404'])->toBe(messageBody());
});

it('yields the plain name to a component that already holds it with a different body', function (): void {
    // A class genuinely called `Error404` is nobody's fault and the registry got there first, so the
    // shared shape takes a discriminated name rather than overwriting one that already has `$ref`s.
    $doc = transformedErrorDoc([
        'paths' => [
            '/a' => ['get' => ['responses' => ['404' => messageBody()]]],
            '/b' => ['get' => ['responses' => ['404' => messageBody()]]],
        ],
        'components' => ['schemas' => ['Error404' => ['type' => 'string']]],
    ]);

    expect($doc['components']['schemas']['Error404'])->toBe(['type' => 'string'])
        ->and(array_keys($doc['components']['schemas']))->toHaveCount(2)
        ->and(schemaRefAt($doc, '/a', '404'))->toMatch('#^\#/components/schemas/Error404_[a-z2-7]{8}$#');
});

it('reuses a component a previous build already registered', function (): void {
    // Re-running over an emitted document (a restored snapshot, say) must not duplicate the entry it
    // already made, so an unchanged document stays byte-identical.
    $existing = errorDoc(['/a' => ['404' => messageBody()], '/b' => ['404' => messageBody()]]);

    $doc = transformedErrorDoc([
        'paths' => [
            '/a' => ['get' => ['responses' => ['404' => messageBody()]]],
            '/b' => ['get' => ['responses' => ['404' => messageBody()]]],
        ],
        'components' => ['schemas' => $existing['components']['schemas']],
    ]);

    expect(array_keys($doc['components']['schemas']))->toBe(['Error404']);
});

it('is a no-op the second time it runs', function (): void {
    // A `$ref` is left alone, which is what makes the transform idempotent — a rebuild over its own
    // output cannot hoist a pointer to a pointer.
    $once = errorDoc(['/a' => ['404' => messageBody()], '/b' => ['404' => messageBody()]]);

    expect(transformedErrorDoc($once))->toBe($once);
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
    ['a response that is not a map', ['paths' => ['/a' => ['get' => ['responses' => ['404' => 'nonsense']]]]]],
    ['content that is not a map', ['paths' => ['/a' => ['get' => ['responses' => ['404' => ['content' => 'nonsense']]]]]]],
    ['media types that are not maps', [
        'paths' => [
            '/a' => ['get' => ['responses' => ['404' => ['content' => ['application/json' => 'nonsense']]]]],
            '/b' => ['get' => ['responses' => ['404' => ['content' => ['application/json' => 'other nonsense']]]]],
        ],
    ]],
    ['a non-numeric status', [
        'paths' => [
            '/a' => ['get' => ['responses' => ['default' => messageBody()]]],
            '/b' => ['get' => ['responses' => ['default' => messageBody()]]],
        ],
    ]],
]);

it('hoists the readable operations of a document that also contains junk', function (): void {
    $doc = transformedErrorDoc(['paths' => [
        '/a' => ['get' => ['responses' => ['404' => messageBody()]], 'post' => 'nonsense'],
        '/b' => 'nonsense',
        '/c' => ['get' => ['responses' => ['404' => messageBody()]], 'put' => ['responses' => 'nonsense']],
    ]]);

    expect(schemaRefAt($doc, '/a', '404'))->toBe('#/components/schemas/Error404')
        ->and(schemaRefAt($doc, '/c', '404'))->toBe('#/components/schemas/Error404')
        ->and($doc['paths']['/b'])->toBe('nonsense')
        ->and($doc['components']['schemas'])->toHaveKey('Error404');
});

it('is deterministic, discriminated names included', function (): void {
    $responses = [
        '/a' => ['404' => messageBody()],
        '/b' => ['404' => messageBody()],
        '/c' => ['404' => ['description' => 'Not Found', 'content' => ['application/problem+json' => ['schema' => ['type' => 'object']]]]],
        '/d' => ['404' => ['description' => 'Not Found', 'content' => ['application/problem+json' => ['schema' => ['type' => 'object']]]]],
    ];

    expect(json_encode(errorDoc($responses)))->toBe(json_encode(errorDoc($responses)));
});

it('emits the shared shape and the per-operation example unchanged in 3.2, 3.1 and 3.0 alike', function (string $version, Emitter $emitter): void {
    // Why the example lives on the MEDIA TYPE and not inside the schema. A Media Type Object's `example`
    // sits outside the schema, so it survives every version untouched and the `$ref` stays bare. The
    // 2020-12 alternative — `{"$ref": …, "examples": […]}` inside the schema — is legal in 3.1 and 3.2 but
    // costs 3.0 an `allOf` wrapper and two downlevel notes PER OPERATION, which is the opposite of a
    // shared definition.
    $body = examplableBody(['code' => 'forbidden']);
    $doc = errorDoc(['/a' => ['403' => $body], '/b' => ['403' => examplableBody(['code' => 'denied'])]]);

    $emitted = json_decode($emitter->emit(UirDocument::fromArray([
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
    ] + $doc)), true, flags: JSON_THROW_ON_ERROR);

    $media = $emitted['paths']['/a']['get']['responses']['403']['content']['application/problem+json'];

    expect($media['schema'])->toBe(['$ref' => '#/components/schemas/Error403'])
        ->and($media['example'])->toBe(['code' => 'forbidden'])
        ->and($emitted['components']['schemas'])->toHaveKey('Error403');

    if ($emitter instanceof OpenApi30DownlevelEmitter || $emitter instanceof OpenApi31DownlevelEmitter) {
        // The schema's per-route provenance is stripped before the downlevel walk, so the `$ref` it sits
        // beside never trips the ref-siblings hoist.
        expect(array_map(static fn ($d): string => $d->code, $emitter->emitWithReport(UirDocument::fromArray([
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
        ] + $doc))->report->diagnostics))->toBe([]);
    }
})->with([
    '3.2' => ['3.2', new OpenApi32Emitter],
    '3.1' => ['3.1', new OpenApi31DownlevelEmitter],
    '3.0' => ['3.0', new OpenApi30DownlevelEmitter],
]);

it('pushes a discriminated name past a component that already holds it', function (): void {
    // The degradation path: an overlay (or a class) can occupy any name, discriminated ones included.
    // The shape still gets published, under a suffix ordered by the contesting set — never merged into
    // the body that was already there.
    $responses = [
        '/a' => ['403' => messageBody('Forbidden')],
        '/b' => ['403' => messageBody('Forbidden')],
        '/c' => ['403' => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]]]]]],
        '/d' => ['403' => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]]]]]],
    ];

    // Learn the name the first shape claims, then hand it to something else and rebuild.
    $taken = substr((string) schemaRefAt(errorDoc($responses), '/a', '403'), strlen('#/components/schemas/'));

    $doc = transformedErrorDoc([
        'paths' => array_map(static fn (array $r): array => ['get' => ['responses' => $r]], $responses),
        'components' => ['schemas' => [$taken => ['type' => 'string']]],
    ]);

    expect($doc['components']['schemas'][$taken])->toBe(['type' => 'string'])
        ->and(schemaRefAt($doc, '/a', '403'))->toBe('#/components/schemas/'.$taken.'_2')
        ->and($doc['components']['schemas'][$taken.'_2'])->toHaveKey('properties');
});

it('shares a repeated response whose media type it cannot read, without reading it', function (): void {
    // The shape pass finds no schema to hoist and says so by doing nothing; the response pass still sees
    // two identical responses. Sharing bytes it cannot parse is safe — it never has to understand them.
    $junk = ['description' => 'Not Found', 'content' => ['application/json' => 'nonsense']];

    $doc = errorDoc(['/a' => ['404' => $junk], '/b' => ['404' => $junk]]);

    expect($doc['components']['schemas'] ?? [])->toBe([])
        ->and($doc['components']['responses']['Error404'])->toBe($junk)
        ->and(responseRefAt($doc, '/a', '404'))->toBe('#/components/responses/Error404');
});

it('points a shared response at the shared shape rather than carrying its own copy', function (): void {
    // Why the shape pass runs FIRST. Run the other way round and the shared response would hold an
    // anonymous inline schema, which a code generator names after the response instead of reusing the
    // one type — the exact fragmentation the shape pass exists to prevent.
    $doc = errorDoc(['/a' => ['404' => messageBody()], '/b' => ['404' => messageBody()]]);

    $schema = $doc['components']['responses']['Error404']['content']['application/json']['schema'];

    expect($schema)->toBe(['$ref' => '#/components/schemas/Error404'])
        ->and($doc['components']['schemas']['Error404'])->toHaveKey('properties');
});

it('leaves a response that states both a reference and a body alone', function (): void {
    // A Reference Object defines no `content`, so whatever built this is saying something the
    // transformer cannot safely rewrite — it declines rather than guessing which half is the truth.
    $mixed = ['$ref' => '#/components/responses/ProblemNotFound'] + messageBody();

    $doc = errorDoc(['/a' => ['404' => $mixed], '/b' => ['404' => $mixed]]);

    expect($doc['components'] ?? [])->toBe([])
        ->and($doc['paths']['/a']['get']['responses']['404'])->toBe($mixed);
});

it('shares two shapes one structural identity would have collapsed', function (string $case, array $one, array $two): void {
    // A component id derived the way an INLINE schema's is would normalise annotations and `required`
    // order away, publishing both of these under one id — and a differ that pairs components by id
    // would only ever see one of them. A published component's id is its exact bytes, so both share.
    $body = static fn (array $schema): array => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => $schema]]];

    $doc = errorDoc([
        '/a' => ['403' => $body($one)], '/b' => ['403' => $body($one)],
        '/c' => ['403' => $body($two)], '/d' => ['403' => $body($two)],
    ]);

    $ids = array_map(static fn (array $s): string => $s['x-docuccino']['id'], $doc['components']['schemas']);

    expect($doc['components']['schemas'])->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2)
        ->and(schemaRefAt($doc, '/a', '403'))->not->toBe(schemaRefAt($doc, '/c', '403'));
})->with([
    [
        'a schema-level annotation',
        ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
        ['type' => 'object', 'properties' => ['message' => ['type' => 'string']], 'description' => 'The error body.'],
    ],
    [
        'the order of required',
        ['type' => 'object', 'properties' => ['x' => ['type' => 'string'], 'y' => ['type' => 'string']], 'required' => ['x', 'y']],
        ['type' => 'object', 'properties' => ['x' => ['type' => 'string'], 'y' => ['type' => 'string']], 'required' => ['y', 'x']],
    ],
]);

it('gives two statuses that share a shape their own component each, under their own id', function (): void {
    // Stock Laravel says `{message}` at 401, 403 and 404 alike. Keeping the status in the identity is
    // what stops an edit to one status repointing another — and what stops two components sharing an id.
    $doc = errorDoc([
        '/a' => ['403' => messageBody('Forbidden'), '404' => messageBody()],
        '/b' => ['403' => messageBody('Forbidden'), '404' => messageBody()],
    ]);

    $ids = array_map(static fn (array $s): string => $s['x-docuccino']['id'], $doc['components']['schemas']);

    expect(array_keys($doc['components']['schemas']))->toBe(['Error403', 'Error404'])
        ->and(array_unique($ids))->toHaveCount(2);
});

it('publishes one id per component', function (): void {
    // The invariant behind the rule above, stated over a document with several statuses and shapes.
    $doc = errorDoc([
        '/a' => ['403' => messageBody('Forbidden'), '404' => messageBody()],
        '/b' => ['403' => messageBody('Forbidden'), '404' => messageBody()],
        '/c' => ['422' => ['description' => 'Unprocessable Entity', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['errors' => ['type' => 'object']]]]]]],
        '/d' => ['422' => ['description' => 'Unprocessable Entity', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['errors' => ['type' => 'object']]]]]]],
    ]);

    $ids = array_map(static fn (array $s): string => $s['x-docuccino']['id'], $doc['components']['schemas']);

    expect($ids)->toHaveCount(3)
        ->and(array_unique($ids))->toHaveCount(3);
});

it('retires the plain response name when two responses contest a status', function (): void {
    // The response bucket mints names the same way the schema bucket does, and for the same reason.
    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/c' => ['403' => examplableBody(['code' => 'denied'])],
        '/d' => ['403' => examplableBody(['code' => 'denied'])],
    ]);

    $names = array_keys($doc['components']['responses']);

    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('Error403')
        ->and($names)->not->toContain('Error403_2')
        ->and($names)->each->toMatch('/^Error403_[a-z2-7]{8}$/')
        // …while the one SHAPE underneath them keeps the plain name, uncontested.
        ->and(array_keys($doc['components']['schemas']))->toBe(['Error403'])
        ->and(schemaRefAt($doc, '/a', '403', 'application/problem+json'))
        ->toBe(schemaRefAt($doc, '/c', '403', 'application/problem+json'));
});

it('names a response the same wherever the document meets it', function (): void {
    $one = ['403' => examplableBody(['code' => 'forbidden'])];
    $two = ['403' => examplableBody(['code' => 'denied'])];

    $first = errorDoc(['/a' => $one, '/b' => $one, '/c' => $two, '/d' => $two]);
    $second = errorDoc(['/a' => $two, '/b' => $two, '/c' => $one, '/d' => $one]);

    expect(responseRefAt($first, '/a', '403'))->toBe(responseRefAt($second, '/c', '403'))
        ->and(responseRefAt($first, '/c', '403'))->toBe(responseRefAt($second, '/a', '403'))
        ->and(array_keys($first['components']['responses']))->toBe(array_keys($second['components']['responses']));
});

it('promotes a body from inline to a reference once a second operation states it', function (): void {
    // The threshold is not local, and this pins the trade rather than hiding it: a second occurrence
    // moves the FIRST one from inline to `$ref`. What it cannot do is change what either one MEANS —
    // the body is the same body either way, which is why the trade is acceptable where a name changing
    // meaning would not be. See MIN_OCCURRENCES.
    $alone = errorDoc(['/a' => ['404' => messageBody()]]);
    $joined = errorDoc(['/a' => ['404' => messageBody()], '/b' => ['404' => messageBody()]]);

    expect($alone['paths']['/a']['get']['responses']['404'])->toBe(messageBody())
        ->and(responseRefAt($joined, '/a', '404'))->toBe('#/components/responses/Error404')
        // Same contract on both sides of the move: resolving the reference gives back the same body.
        ->and(responseAt($joined, '/a', '404')['description'])->toBe('Not Found')
        ->and($joined['components']['schemas']['Error404']['properties'])
        ->toBe(messageShape()['properties']);
});
