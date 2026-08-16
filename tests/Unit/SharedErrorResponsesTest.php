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
use Docuccino\Core\Overlay\OverlayApplier;
use Docuccino\Core\Overlay\OverlayDocument;

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

/** The diagnostics one run raises, in the order it raised them. */
function errorDocReport(array $doc, array $representation = []): array
{
    $context = new DocumentContext(new DocumentConfig('default', [], representation: $representation), 'doc:default');
    (new SharedErrorResponses)->transform(new UirDocumentDraft($doc), $context);

    return $context->diagnostics->all();
}

/**
 * A response body whose producer declared the component name it publishes under, spelled the way
 * `ResponseDraft::claimComponentName()` freezes it.
 */
function claimedBody(string $name, array $body, ?string $producer = null): array
{
    $extension = is_array($body['x-docuccino'] ?? null) ? $body['x-docuccino'] : [];

    if ($producer !== null) {
        $extension['provenance'] = [['producer' => $producer, 'fields' => ['component', 'description']]];
    }

    $extension['facts'] = ['component' => $name];
    unset($body['x-docuccino']);

    return ['x-docuccino' => $extension] + $body;
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

it('publishes a declared name in both buckets, in place of the status', function (): void {
    // What a producer that speaks for one kind of error buys: the generated client catches `NotFound`
    // rather than `Error404`, in the response component and the shape underneath it alike.
    $body = claimedBody('NotFound', messageBody());

    $doc = errorDoc(['/a' => ['404' => $body], '/b' => ['404' => $body]]);

    expect(array_keys($doc['components']['schemas']))->toBe(['NotFound'])
        ->and(array_keys($doc['components']['responses']))->toBe(['NotFound'])
        ->and(responseRefAt($doc, '/a', '404'))->toBe('#/components/responses/NotFound')
        ->and($doc['components']['responses']['NotFound']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/NotFound']);
});

it('leaves the declaration on the operation and out of the component it names', function (): void {
    // The claim is a per-route provenance fact like the id beside it, so it travels with the `$ref` and
    // never into the shared body — which speaks for every route pointing at it.
    $body = claimedBody('NotFound', messageBody('Not Found', 'integration:framework-errors'));

    $doc = errorDoc(['/a' => ['404' => $body], '/b' => ['404' => $body]]);

    expect($doc['paths']['/a']['get']['responses']['404']['x-docuccino']['facts'])->toBe(['component' => 'NotFound'])
        ->and($doc['components']['responses']['NotFound'])->not->toHaveKey('x-docuccino')
        ->and($doc['components']['schemas']['NotFound'])->not->toHaveKey('facts');
});

it('gives two declared names that share a body a component each', function (): void {
    // The point of keying the hoist on the declaration. Two producers naming two different errors that
    // happen to spell the same body get two named components — and neither name is a function of the
    // other's existence, which is what a single shared `Error404` could never promise.
    $one = claimedBody('NotFound', messageBody());
    $two = claimedBody('GoneAway', messageBody());

    $doc = errorDoc([
        '/a' => ['404' => $one], '/b' => ['404' => $one],
        '/c' => ['404' => $two], '/d' => ['404' => $two],
    ]);

    expect(array_keys($doc['components']['schemas']))->toBe(['GoneAway', 'NotFound'])
        ->and(schemaRefAt($doc, '/a', '404'))->toBe('#/components/schemas/NotFound')
        ->and(schemaRefAt($doc, '/c', '404'))->toBe('#/components/schemas/GoneAway')
        // Two published components are two published nodes, so they cannot share one id.
        ->and($doc['components']['schemas']['NotFound']['x-docuccino']['id'])
        ->not->toBe($doc['components']['schemas']['GoneAway']['x-docuccino']['id']);
});

it('leaves an undeclared body exactly where it was when a declared one arrives', function (string $case, array $arriving): void {
    // Locality across the feature boundary, on the hardest version of it: the arriving route states the
    // SAME status and the SAME bytes, so the two would be one component if a declaration were only a
    // label. Every byte the undeclared pair already published — both `$ref`s and both components — has
    // to survive a producer elsewhere in the application learning to name its error.
    $undeclared = ['404' => messageBody()];

    $before = errorDoc(['/a' => $undeclared, '/b' => $undeclared]);
    $after = errorDoc(['/a' => $undeclared, '/b' => $undeclared, '/c' => $arriving, '/d' => $arriving]);

    expect(schemaRefAt($after, '/a', '404'))->toBe(schemaRefAt($before, '/a', '404'))
        ->and(responseRefAt($after, '/a', '404'))->toBe(responseRefAt($before, '/a', '404'))
        ->and($after['components']['schemas']['Error404'])->toBe($before['components']['schemas']['Error404'])
        ->and($after['components']['responses']['Error404'])->toBe($before['components']['responses']['Error404'])
        // …and the arrival really did arrive, so the rows above are not equality between two nothings.
        ->and($after['components']['schemas'])->toHaveCount(count($before['components']['schemas']) + 1)
        ->and($after['components']['responses'])->toHaveCount(count($before['components']['responses']) + 1);
})->with([
    ['the same status and the same body', ['404' => claimedBody('NotFound', messageBody())]],
    ['the same status and a body of its own', ['404' => claimedBody('NotFound', ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]]]]])]],
    ['a status of its own', ['403' => claimedBody('Forbidden', messageBody('Forbidden'))]],
]);

it('shares a body a declaring and a non-declaring producer both state', function (): void {
    // What repeats decides WHETHER a body is hoisted; the declaration decides only what the component is
    // called. Counting per declared name instead would leave each of these alone in a bucket of one, and
    // a body two operations state would go out inline twice — a declaration taking a shared component
    // away from a route that never declared anything.
    $doc = errorDoc([
        '/a' => ['404' => claimedBody('NotFound', messageBody())],
        '/b' => ['404' => messageBody()],
    ]);

    expect(array_keys($doc['components']['schemas']))->toBe(['NotFound', 'Error404'])
        ->and(schemaRefAt($doc, '/a', '404'))->toBe('#/components/schemas/NotFound')
        ->and(schemaRefAt($doc, '/b', '404'))->toBe('#/components/schemas/Error404')
        ->and(responseRefAt($doc, '/a', '404'))->toBe('#/components/responses/NotFound')
        ->and(responseRefAt($doc, '/b', '404'))->toBe('#/components/responses/Error404');
});

it('publishes an undeclared body exactly as it would with no declaration in the document', function (): void {
    // The invariant stated as an equality. An undeclared body's whole representation — its `$ref`s and
    // the components behind them — is a function of the statuses and bodies the document states, and
    // nothing a producer declares anywhere can move it.
    $bare = errorDoc(['/a' => ['404' => messageBody()], '/b' => ['404' => messageBody()]]);

    $mixed = errorDoc([
        '/a' => ['404' => messageBody()],
        '/b' => ['404' => messageBody()],
        '/c' => ['404' => claimedBody('NotFound', messageBody())],
    ]);

    expect($mixed['paths']['/a'])->toBe($bare['paths']['/a'])
        ->and($mixed['paths']['/b'])->toBe($bare['paths']['/b'])
        ->and($mixed['components']['schemas']['Error404'])->toBe($bare['components']['schemas']['Error404'])
        ->and($mixed['components']['responses']['Error404'])->toBe($bare['components']['responses']['Error404'])
        // …and the arriving route, alone in naming this body, is hoisted all the same: the body it
        // states is one the document already states twice, and that is the whole question.
        ->and(responseRefAt($mixed, '/c', '404'))->toBe('#/components/responses/NotFound');
});

it('names a declared body the same wherever the document meets it', function (): void {
    // Filed by name and content, never by the walk: reversing which shape the document states first
    // moves nothing.
    $one = ['404' => claimedBody('NotFound', messageBody())];
    $two = ['404' => claimedBody('GoneAway', messageBody('Not Found here'))];

    $first = errorDoc(['/a' => $one, '/b' => $one, '/c' => $two, '/d' => $two]);
    $second = errorDoc(['/a' => $two, '/b' => $two, '/c' => $one, '/d' => $one]);

    expect(schemaRefAt($first, '/a', '404'))->toBe(schemaRefAt($second, '/c', '404'))
        ->and(array_keys($first['components']['schemas']))->toBe(array_keys($second['components']['schemas']))
        ->and(json_encode($first['components']))->toBe(json_encode($second['components']));
});

it('retires a declared name two different bodies contest, and says who asked', function (): void {
    // A genuine contest, settled by the one ladder every minted name climbs — not by a second mechanism
    // of this transformer's own.
    $one = claimedBody('NotFound', messageBody());
    $two = claimedBody('NotFound', ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]]]]]);

    $doc = errorDoc([
        '/a' => ['404' => $one], '/b' => ['404' => $one],
        '/c' => ['404' => $two], '/d' => ['404' => $two],
    ]);
    $names = array_keys($doc['components']['schemas']);

    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('NotFound')
        ->and($names)->each->toMatch('/^NotFound_[a-z2-7]{8}$/');

    $collision = array_values(array_filter(
        errorDocReport(['paths' => array_map(static fn (array $r): array => ['get' => ['responses' => $r]], [
            '/a' => ['404' => $one], '/b' => ['404' => $one],
            '/c' => ['404' => $two], '/d' => ['404' => $two],
        ])]),
        static fn ($d): bool => $d->code === 'components.name-collision',
    ));

    expect($collision)->not->toBeEmpty()
        ->and($collision[0]->message)->toContain('"NotFound"')
        ->and($collision[0]->message)->toContain($names[0]);
});

it('climbs past a declared name a component already holds with another body', function (): void {
    // `$taken` is the registry's, and a declaration does not outrank it: the incumbent keeps its name
    // and the shared body takes a discriminated one rather than overwriting something already `$ref`ed.
    $body = claimedBody('NotFound', messageBody());

    $doc = transformedErrorDoc([
        'paths' => [
            '/a' => ['get' => ['responses' => ['404' => $body]]],
            '/b' => ['get' => ['responses' => ['404' => $body]]],
        ],
        'components' => ['schemas' => ['NotFound' => ['type' => 'string']]],
    ]);

    expect($doc['components']['schemas']['NotFound'])->toBe(['type' => 'string'])
        ->and(schemaRefAt($doc, '/a', '404'))->toMatch('#^\#/components/schemas/NotFound_[a-z2-7]{8}$#');
});

it('refuses a declared name no component key could carry, and says whose it was', function (): void {
    // An author error must cost the document a better name, never its validity — so the body falls back
    // to the status and the producer is named where the author will see it.
    $body = claimedBody('Not Found!', messageBody(), 'integration:framework-errors');
    $paths = ['paths' => [
        '/a' => ['get' => ['responses' => ['404' => $body]]],
        '/b' => ['get' => ['responses' => ['404' => $body]]],
    ]];

    $doc = transformedErrorDoc($paths);
    $rejected = array_values(array_filter(errorDocReport($paths), static fn ($d): bool => $d->code === 'components.name-invalid'));

    expect(array_keys($doc['components']['schemas']))->toBe(['Error404'])
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->message)->toContain('"integration:framework-errors"')
        ->and($rejected[0]->message)->toContain('"Not Found!"');
});

it('says nothing about a rejected name on a body that was never going to hoist', function (string $case, array $paths): void {
    // A diagnostic earns its place by where it fires. The message says the body "was named after its
    // status instead", which is only true of a body that got published — a 2xx is none of this
    // transformer's business and a body stated once stays inline whatever anyone called it.
    expect(errorDocReport(['paths' => $paths]))->toBe([]);
})->with(function (): array {
    $body = claimedBody('Not Found!', messageBody(), 'integration:acme');

    return [
        ['a body stated once', ['/a' => ['get' => ['responses' => ['404' => $body]]]]],
        ['a success body', [
            '/a' => ['get' => ['responses' => ['200' => claimedBody('Not Found!', messageBody('OK'), 'integration:acme')]]],
            '/b' => ['get' => ['responses' => ['200' => claimedBody('Not Found!', messageBody('OK'), 'integration:acme')]]],
        ]],
        ['two bodies neither of which repeats', [
            '/a' => ['get' => ['responses' => ['404' => $body]]],
            '/b' => ['get' => ['responses' => ['409' => claimedBody('Not Found!', messageBody('Conflict'), 'integration:acme')]]],
        ]],
    ];
});

it('names no producer for an illegal name an overlay wrote, because no record owns the field', function (): void {
    // The one source of an illegal name left, and the one arm of the message a hand-built document does
    // not have to fake: `claimComponentName()` refuses one at the write, so a name this transformer can
    // reject reached the document some other way — an overlay, which runs before the transformers. Its
    // provenance record owns the `x-docuccino` member it merged and not the `component` inside it, so
    // there is no producer to name and the diagnostic says only what it knows.
    $body = messageBody();
    $overlaid = (new OverlayApplier)->apply(
        ['paths' => [
            '/a' => ['get' => ['responses' => ['404' => $body]]],
            '/b' => ['get' => ['responses' => ['404' => $body]]],
        ]],
        OverlayDocument::fromArray([
            'overlay' => '1.0.0',
            'actions' => array_map(static fn (string $path): array => [
                'target' => sprintf('$.paths[\'%s\'].get.responses[\'404\']', $path),
                'update' => ['x-docuccino' => ['facts' => ['component' => 'Not Found!']]],
            ], ['/a', '/b']),
        ]),
    )->document;

    $rejected = array_values(array_filter(errorDocReport($overlaid), static fn ($d): bool => $d->code === 'components.name-invalid'));

    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->message)->toStartWith('A producer declared the component name "Not Found!"')
        // …and the body still publishes, under the name its status gives it.
        ->and(array_keys(transformedErrorDoc($overlaid)['components']['schemas']))->toBe(['Error404']);
});

it('quotes a rejected name without letting it write to the terminal', function (): void {
    // A diagnostic is read on a terminal, and the only names that reach this one are by definition ones
    // nothing validated — an overlay states `x-docuccino.facts.component` on whatever it likes, and the
    // hoist reads the document. An escape sequence would repaint the line and a newline would forge a
    // second diagnostic, so the control characters are shown rather than performed.
    $body = claimedBody("Evil\x1b[31m\nName", messageBody(), "acme\x07");
    $paths = ['paths' => [
        '/a' => ['get' => ['responses' => ['404' => $body]]],
        '/b' => ['get' => ['responses' => ['404' => $body]]],
    ]];

    $rejected = array_values(array_filter(errorDocReport($paths), static fn ($d): bool => $d->code === 'components.name-invalid'));

    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->message)->toContain('Evil\x1B[31m\x0AName')
        ->and($rejected[0]->message)->toContain('acme\x07')
        ->and(preg_match('/[\x00-\x1F\x7F]/', $rejected[0]->message))->toBe(0)
        // The name is still legible enough to recognise, which is the whole point of quoting it.
        ->and($rejected[0]->message)->toContain('Name')
        // …and the component the body actually got is the status fallback, unaffected.
        ->and(array_keys(transformedErrorDoc($paths)['components']['schemas']))->toBe(['Error404']);
});

it('reports one rejected name per producer, however many routes state it', function (): void {
    // A mapper wrong on one route is wrong on every route it maps; one warning is the whole story.
    $body = claimedBody('Not Found!', messageBody(), 'integration:framework-errors');
    $paths = ['paths' => array_map(
        static fn (): array => ['get' => ['responses' => ['404' => $body]]],
        array_flip(['/a', '/b', '/c', '/d']),
    )];

    expect(array_filter(errorDocReport($paths), static fn ($d): bool => $d->code === 'components.name-invalid'))
        ->toHaveCount(1);
});

it('walks past a declaration it cannot read rather than reporting on it', function (string $case, mixed $fact): void {
    // An overlay or a hand-written document can put anything anywhere. A member that is not a name is
    // not a mistaken name; the body simply keeps the one its status gives it.
    $body = ['x-docuccino' => ['facts' => ['component' => $fact]]] + messageBody();
    $paths = ['paths' => [
        '/a' => ['get' => ['responses' => ['404' => $body]]],
        '/b' => ['get' => ['responses' => ['404' => $body]]],
    ]];

    expect(array_keys(transformedErrorDoc($paths)['components']['schemas']))->toBe(['Error404'])
        ->and(errorDocReport($paths))->toBe([]);
})->with([
    ['a number', 404],
    ['an empty string', ''],
    ['a map', ['name' => 'NotFound']],
    ['a boolean', true],
]);

it('is a no-op the second time it runs over a declared body', function (): void {
    $once = errorDoc([
        '/a' => ['404' => claimedBody('NotFound', messageBody())],
        '/b' => ['404' => claimedBody('NotFound', messageBody())],
    ]);

    expect(transformedErrorDoc($once))->toBe($once);
});
