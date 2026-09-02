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
 * id-based semantic diff still sees one response per operation. Messaging: `description` and `headers`
 * are part of what a response IS, so they never merge away, while the media type's `example` is how an
 * operation illustrates it — it comes off the key and travels into the shared response as one of its
 * `examples`, so a difference in illustration never splits one error into two definitions.
 * Deliberately narrow: 4xx/5xx, shapes that repeat, bodies that exist.
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
function examplableBody(mixed $example, string $description = 'Forbidden'): array
{
    return [
        'description' => $description,
        'content' => ['application/problem+json' => [
            'schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'hint' => ['type' => 'string']]],
            'example' => $example,
        ]],
    ];
}

/**
 * The same `{code, hint}` body illustrated by a producer that FILLED some of the example in — the members
 * it named are the ones nothing but their declared type answered for, recorded the way
 * `ResponseDraft::setExample()` freezes them.
 */
function filledBody(mixed $example, array $filled, string $description = 'Forbidden'): array
{
    $body = examplableBody($example, $description);

    return ['x-docuccino' => ['facts' => ['examplePlaceholders' => ['application/problem+json' => $filled]]]] + $body;
}

/**
 * One status answered with TWO representations: the problem body a renderer already publishes as a
 * component, and an anonymous union of challenge shapes beside it. Neither is "the" body of the
 * response, which is what makes the response's own name no name for either of them.
 */
function twoRepresentationBody(string $description = 'Unprocessable Entity'): array
{
    return [
        'description' => $description,
        'content' => [
            'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetails']],
            'application/json' => ['schema' => ['anyOf' => [
                ['type' => 'object', 'properties' => ['otp' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['sso' => ['type' => 'string']]],
            ]]],
        ],
    ];
}

/**
 * The reported shape: one status answered with the problem body every other operation answers it with,
 * and a second representation beside it — both of them components the document already publishes, which
 * is what a `#[Response(type: SomeUnion::class)]` beside a Data-class problem body actually looks like.
 */
function twoNamedRepresentationBody(string $description = 'Unprocessable Entity'): array
{
    return [
        'description' => $description,
        'content' => [
            'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetailsData']],
            'application/json' => ['schema' => ['$ref' => '#/components/schemas/AuthenticationChallenge']],
        ],
    ];
}

/**
 * The same claim, saying of itself that it names the WHOLE response — every representation the status
 * answers with — rather than the one body its claimer built. The claimer's own statement, since nothing
 * a reader can compute off the finished response tells the two apart.
 */
function wholeResponseBody(string $name, array $body): array
{
    $claimed = claimedBody($name, $body);
    $claimed['x-docuccino']['facts']['componentNamesResponse'] = true;

    return $claimed;
}

/** The one representation the rest of the document answers that status with. */
function oneNamedRepresentationBody(string $description = 'Unprocessable Entity'): array
{
    return [
        'description' => $description,
        'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetailsData']]],
    ];
}

/** A document of `[path => [status => response]]`, with the components a real one would already publish. */
function errorDocWithSchemas(array $responsesByPath, array $schemas): array
{
    $paths = [];
    foreach ($responsesByPath as $path => $responses) {
        $paths[$path] = ['get' => ['responses' => $responses]];
    }

    return transformedErrorDoc(['paths' => $paths, 'components' => ['schemas' => $schemas]]);
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

/** The single example a given path's error response documents, or null where it documents several. */
function exampleAt(array $doc, string $path, string $status, string $mediaType = 'application/problem+json'): mixed
{
    return responseAt($doc, $path, $status)['content'][$mediaType]['example'] ?? null;
}

/** The example bodies a given path's error response offers, under the keys they were minted with. */
function examplesAt(array $doc, string $path, string $status, string $mediaType = 'application/problem+json'): array
{
    $examples = responseAt($doc, $path, $status)['content'][$mediaType]['examples'] ?? [];

    return is_array($examples) ? $examples : [];
}

/** Just the example bodies, in key order — what a reader of the document is shown. */
function exampleValuesAt(array $doc, string $path, string $status, string $mediaType = 'application/problem+json'): array
{
    return array_values(array_map(static fn (array $example): mixed => $example['value'], examplesAt($doc, $path, $status, $mediaType)));
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

it('shares one response between operations that illustrate it differently', function (): void {
    // Two operations stating one contract — one schema, one description — are one response however each
    // chooses to illustrate it. Splitting them would hand an SDK consumer two structurally identical
    // types for one concept, neither named after anything but a hash; instead they share ONE component
    // carrying both illustrations.
    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/b' => ['403' => examplableBody(['code' => 'denied'])],
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error403'])
        ->and(responseRefAt($doc, '/a', '403'))->toBe('#/components/responses/Error403')
        ->and(responseRefAt($doc, '/b', '403'))->toBe('#/components/responses/Error403')
        ->and(schemaRefAt($doc, '/a', '403', 'application/problem+json'))->toBe('#/components/schemas/Error403')
        // Neither illustration was lost, and neither is the media type's singular `example` any more.
        ->and(exampleValuesAt($doc, '/a', '403'))->toBe([['code' => 'denied'], ['code' => 'forbidden']])
        ->and(exampleAt($doc, '/a', '403'))->toBeNull();
});

it('mints an example key from that example alone, so an arriving arm renames nothing', function (): void {
    // The same invariant every published name owes, on the one kind of name whose opacity is cheap: no
    // code generator turns an example key into a type, so it can be paid for in readability. What it
    // cannot be is a function of the set — an arm arriving must add a key and move none.
    $before = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/b' => ['403' => examplableBody(['code' => 'denied'])],
    ]);
    $after = errorDoc([
        // …and arriving FIRST, where a walk-ordered key would have been most obviously wrong.
        '/z' => ['403' => examplableBody(['code' => 'expired'])],
        '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/b' => ['403' => examplableBody(['code' => 'denied'])],
    ]);

    $keys = array_keys(examplesAt($before, '/a', '403'));

    expect($keys)->toHaveCount(2)
        ->and($keys)->each->toMatch('/^example_[a-z2-7]{8}$/')
        ->and(array_keys(examplesAt($after, '/a', '403')))->toHaveCount(3)
        ->and(array_intersect($keys, array_keys(examplesAt($after, '/a', '403'))))->toBe($keys)
        // Every surviving key still names the body it always named.
        ->and(array_intersect_key(examplesAt($after, '/a', '403'), examplesAt($before, '/a', '403')))
        ->toBe(examplesAt($before, '/a', '403'));
});

it('merges two arms that word one body differently, and keeps each arm\'s words with it', function (): void {
    // A description is prose about a body, not the body, so two arms wording one contract differently are
    // one component — and the words the shared one does not publish travel to the `$ref` that overrides
    // them, where OAS 3.1 and 3.2 define exactly that.
    $described = examplableBody(['code' => 'forbidden']);
    $described['description'] = 'You may not do that';

    $doc = errorDoc(['/a' => ['403' => examplableBody(['code' => 'denied'])], '/b' => ['403' => $described]]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error403'])
        ->and($doc['components']['responses']['Error403']['description'])->toBe('Forbidden')
        ->and($doc['paths']['/a']['get']['responses']['403'])->toBe(['$ref' => '#/components/responses/Error403'])
        ->and($doc['paths']['/b']['get']['responses']['403'])
        ->toBe(['$ref' => '#/components/responses/Error403', 'description' => 'You may not do that'])
        // Both illustrations still travel, and both still sit beside the schema they were written for.
        ->and(exampleValuesAt($doc, '/a', '403'))->toBe([['code' => 'denied'], ['code' => 'forbidden']]);
});

it('keeps an example that illustrates no shape out of the merge', function (): void {
    // An example only illustrates something when a schema is there to be illustrated. A media type that
    // states an example and no shape is stating that example and nothing else, so it stays part of what
    // the response IS — merging on it would publish a component asserting nothing at all.
    $bare = static fn (array $example): array => ['description' => 'Not Found', 'content' => ['application/json' => ['example' => $example]]];

    $doc = errorDoc(['/a' => ['404' => $bare(['message' => 'a'])], '/b' => ['404' => $bare(['message' => 'b'])]]);

    expect($doc['components'] ?? [])->toBe([])
        ->and($doc['paths']['/a']['get']['responses']['404'])->toBe($bare(['message' => 'a']));
});

it('keeps a named example on one route out of what an unrelated route publishes', function (): void {
    // The locality rule, on the path that broke it. `/a` and `/b` answer 403 identically; naming `/b`'s
    // example is a change to `/b` alone, and it used to split the group in two — `/a`'s body went back
    // inline and `components.responses.Error403`, a name a generated client is written against, was
    // deleted. It bit at exactly two arms, which is the common case.
    $named = examplableBody(null);
    unset($named['content']['application/problem+json']['example']);
    $named['content']['application/problem+json']['examples'] = ['denied' => ['value' => ['code' => 'denied']]];

    $plain = errorDoc(['/a' => ['403' => examplableBody(['code' => 'forbidden'])], '/b' => ['403' => examplableBody(['code' => 'denied'])]]);
    $doc = errorDoc(['/a' => ['403' => examplableBody(['code' => 'forbidden'])], '/b' => ['403' => $named]]);

    expect(array_keys($plain['components']['responses']))->toBe(['Error403'])
        ->and(array_keys($doc['components']['responses']))->toBe(['Error403'])
        ->and(responseRefAt($doc, '/a', '403'))->toBe('#/components/responses/Error403')
        ->and(responseRefAt($doc, '/b', '403'))->toBe('#/components/responses/Error403');
});

it('publishes an author\'s own example names on the shared response', function (): void {
    // An author's key is a function of their declaration, so it disturbs nothing and reads far better
    // than a hash. Minting climbs past the names they used, so the unnamed arm's illustration lands
    // beside theirs rather than on top of it.
    $named = examplableBody(null);
    unset($named['content']['application/problem+json']['example']);
    $named['content']['application/problem+json']['examples'] = [
        'denied' => ['value' => ['code' => 'denied'], 'summary' => 'Refused outright'],
    ];

    $doc = errorDoc(['/a' => ['403' => examplableBody(['code' => 'forbidden'])], '/b' => ['403' => $named]]);
    $examples = examplesAt($doc, '/a', '403');

    expect($examples['denied'])->toBe(['value' => ['code' => 'denied'], 'summary' => 'Refused outright'])
        ->and($examples)->toHaveCount(2)
        ->and(array_diff(array_keys($examples), ['denied']))->toHaveCount(1)
        ->and(exampleValuesAt($doc, '/a', '403'))->toContain(['code' => 'forbidden']);
});

it('unions the names two arms chose when they agree on what each one means', function (): void {
    $authored = static function (string $key, string $code): array {
        $body = examplableBody(null);
        unset($body['content']['application/problem+json']['example']);
        $body['content']['application/problem+json']['examples'] = [$key => ['value' => ['code' => $code]]];

        return $body;
    };

    $doc = errorDoc(['/a' => ['403' => $authored('expired', 'expired')], '/b' => ['403' => $authored('revoked', 'revoked')]]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error403'])
        ->and(array_keys(examplesAt($doc, '/a', '403')))->toBe(['expired', 'revoked']);
});

it('hands a name two arms gave to two examples to neither of them, and says so', function (): void {
    // The one thing an author has to settle themselves: publishing either body under that name would put
    // one arm's example behind the other's label, and dropping one loses an illustration. So both are
    // published under that name plus their own content — the ladder every contested name in this document
    // climbs — and the reader is told, because the key they wrote is the one thing that is not there.
    $authored = static function (mixed $value): array {
        $body = examplableBody(null);
        unset($body['content']['application/problem+json']['example']);
        $body['content']['application/problem+json']['examples'] = ['denied' => ['value' => $value]];

        return $body;
    };

    $responses = ['/a' => ['403' => $authored(['code' => 'forbidden'])], '/b' => ['403' => $authored(['code' => 'denied'])]];

    $doc = errorDoc($responses);
    $report = errorDocReport(['paths' => [
        '/a' => ['get' => ['responses' => $responses['/a']]],
        '/b' => ['get' => ['responses' => $responses['/b']]],
    ]]);

    $examples = examplesAt($doc, '/a', '403');

    // The component they share is not what pays for the disagreement…
    expect(array_keys($doc['components']['responses']))->toBe(['Error403'])
        ->and(responseRefAt($doc, '/a', '403'))->toBe('#/components/responses/Error403')
        ->and(responseRefAt($doc, '/b', '403'))->toBe('#/components/responses/Error403')
        // …neither illustration is lost, and neither sits under the name the other's author wrote…
        ->and($examples)->toHaveCount(2)
        ->and(array_keys($examples))->each->toMatch('/^denied_[a-z2-7]{8}$/')
        ->and(exampleValuesAt($doc, '/a', '403'))->toContain(['code' => 'forbidden'])
        ->and(exampleValuesAt($doc, '/a', '403'))->toContain(['code' => 'denied'])
        ->and(schemaRefAt($doc, '/a', '403', 'application/problem+json'))->toBe('#/components/schemas/Error403')
        // …and the name that went nowhere is reported rather than quietly missing.
        ->and(array_map(static fn ($d): string => $d->code, $report))->toBe(['components.example-name-conflict'])
        ->and($report[0]->message)->toContain('the name "denied"')
        ->and($report[0]->message)->toContain('Error403');
});

it('lets no difference in wording turn one example name into a contest', function (): void {
    // The locality failure this pass exists to prevent, one field over. `/a` and `/b` word their 404 one
    // way and `/c` and `/d` another, and each pair names its own example `default`. With the wording in
    // the key those were two buckets of two, each agreeing with itself; one bucket of four makes the two
    // names meet, and retiring the bucket over that would delete a component all four operations point
    // at — an unrelated route's wording deciding whether a name a client is written against exists.
    $named = static function (string $description, mixed $value): array {
        $body = messageBody($description);
        $body['content']['application/json']['examples'] = ['default' => ['value' => $value]];

        return $body;
    };

    $responses = [
        '/a' => ['404' => $named('Not Found', ['message' => 'gone'])],
        '/b' => ['404' => $named('Not Found', ['message' => 'gone'])],
        '/c' => ['404' => $named('No taxonomy term matches the given slug', ['message' => 'no such term'])],
        '/d' => ['404' => $named('No taxonomy term matches the given slug', ['message' => 'no such term'])],
    ];

    $doc = errorDoc($responses);
    $report = errorDocReport([
        'paths' => array_map(static fn (array $r): array => ['get' => ['responses' => $r]], $responses),
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error404'])
        ->and(array_map(static fn (string $path): ?string => responseRefAt($doc, $path, '404'), ['/a', '/b', '/c', '/d']))
        ->toBe(array_fill(0, 4, '#/components/responses/Error404'))
        // Both wordings' illustrations publish on it, under the contested name and their own content.
        ->and(examplesAt($doc, '/a', '404', 'application/json'))->toHaveCount(2)
        ->and(array_keys(examplesAt($doc, '/a', '404', 'application/json')))->each->toMatch('/^default_[a-z2-7]{8}$/')
        ->and(array_map(static fn ($d): string => $d->code, $report))->toBe(['components.example-name-conflict']);
});

it('leaves a media type stating both an example and a map exactly as it found it', function (): void {
    // OAS carries `example` or `examples`, never both, so a media type stating both is a document this
    // pass has no business tidying by merging half of it away. It stays part of the body's identity.
    $authored = static function (mixed $example): array {
        $body = examplableBody($example);
        $body['content']['application/problem+json']['examples'] = ['expired' => ['value' => ['code' => 'forbidden']]];

        return $body;
    };

    $a = $authored(['code' => 'a']);
    $b = $authored(['code' => 'b']);
    $doc = errorDoc(['/a' => ['403' => $a], '/b' => ['403' => $b]]);

    $illustrations = static fn (array $body): array => array_intersect_key(
        $body['content']['application/problem+json'],
        ['example' => true, 'examples' => true],
    );

    expect($doc['components']['responses'] ?? null)->toBeNull()
        ->and($illustrations($doc['paths']['/a']['get']['responses']['403']))->toBe($illustrations($a))
        ->and($illustrations($doc['paths']['/b']['get']['responses']['403']))->toBe($illustrations($b));
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
    // Where only the shape is shared — here two arms stating one body under different headers, which are
    // part of what a response IS — the operation keeps its whole response, so the per-route provenance
    // the schema carries has somewhere to live and is not thrown away.
    $a = examplableBody(['code' => 'forbidden']);
    $b = examplableBody(['code' => 'forbidden']);
    $b['headers'] = ['Retry-After' => ['schema' => ['type' => 'integer']]];
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
    // A response the producer already published as a component is shared by definition.
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

it('loses no illustration when several arms collapse into one response', function (): void {
    // Sharing must not cost a route the thing it was saying, and the route that illustrated nothing must
    // not cost the others theirs. Every example survives the collapse, under the one component all three
    // now point at — and each still sits beside exactly the schema it was written against, so none of
    // them can have become false on the way.
    $noExample = examplableBody([]);
    unset($noExample['content']['application/problem+json']['example']);

    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'ask an admin'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'renew your token'])],
        '/c' => ['403' => $noExample],
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error403'])
        ->and(exampleValuesAt($doc, '/a', '403'))->toBe([
            ['code' => 'forbidden', 'hint' => 'ask an admin'],
            ['code' => 'forbidden', 'hint' => 'renew your token'],
        ])
        ->and(responseRefAt($doc, '/c', '403'))->toBe('#/components/responses/Error403')
        ->and($doc['components']['responses']['Error403']['content']['application/problem+json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/Error403']);
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

it('emits the shared shape and every illustration unchanged in 3.2, 3.1 and 3.0 alike', function (string $version, Emitter $emitter): void {
    // Why the illustrations live on the MEDIA TYPE and not inside the schema. A Media Type Object's
    // `example` and `examples` both sit outside the schema and both are defined in 3.0, 3.1 and 3.2, so
    // they survive every version untouched and the `$ref` stays bare. The 2020-12 alternative —
    // `{"$ref": …, "examples": […]}` inside the schema — is legal in 3.1 and 3.2 but costs 3.0 an `allOf`
    // wrapper and a downlevel note, and the 3.0 walk rewrites a SCHEMA's `examples` into a single
    // `example`, which would silently throw away all but one of them.
    $doc = errorDoc(['/a' => ['403' => examplableBody(['code' => 'forbidden'])], '/b' => ['403' => examplableBody(['code' => 'denied'])]]);

    $document = UirDocument::fromArray(['openapi' => '3.2.0', 'info' => ['title' => 'API', 'version' => '1.0.0']] + $doc);
    $emitted = json_decode($emitter->emit($document), true, flags: JSON_THROW_ON_ERROR);

    $media = $emitted['components']['responses']['Error403']['content']['application/problem+json'];

    expect($emitted['paths']['/a']['get']['responses']['403'])->toBe(['$ref' => '#/components/responses/Error403'])
        ->and($media['schema'])->toBe(['$ref' => '#/components/schemas/Error403'])
        ->and($media)->not->toHaveKey('example')
        ->and(array_values(array_map(static fn (array $e): mixed => $e['value'], $media['examples'])))
        ->toBe([['code' => 'denied'], ['code' => 'forbidden']])
        ->and($emitted['components']['schemas'])->toHaveKey('Error403');

    if ($emitter instanceof OpenApi30DownlevelEmitter || $emitter instanceof OpenApi31DownlevelEmitter) {
        // Nothing was downlevelled away: the map is a Media Type Object member every version defines, and
        // the `$ref` it sits beside never trips the ref-siblings hoist.
        expect(array_map(static fn ($d): string => $d->code, $emitter->emitWithReport($document)->report->diagnostics))->toBe([]);
    }
})->with([
    '3.2' => ['3.2', new OpenApi32Emitter],
    '3.1' => ['3.1', new OpenApi31DownlevelEmitter],
    '3.0' => ['3.0', new OpenApi30DownlevelEmitter],
]);

it('keeps the plain name for a contract two operations word differently', function (): void {
    // The reported defect, at the scale it was found: 145 operations answered 404 through one renderer and
    // one taxonomy endpoint worded its own, so `Error404` and `Error404_dl33vd2k` both existed — same
    // `$ref`, same headers, different words — and the taxonomy endpoint's private phrasing renamed the
    // type every other operation handed a generated client. Prose does not change what a response is.
    $shared = messageBody('Resource not found');
    $own = messageBody('No taxonomy term matches the given slug');

    $doc = errorDoc([
        '/a' => ['404' => $shared], '/b' => ['404' => $shared], '/c' => ['404' => $shared],
        '/d' => ['404' => $own], '/e' => ['404' => $own],
    ]);
    $codes = array_map(static fn (object $diagnostic): string => $diagnostic->code, errorDocReport([
        'paths' => array_map(static fn (array $responses): array => ['get' => ['responses' => $responses]], [
            '/a' => ['404' => $shared], '/b' => ['404' => $shared], '/c' => ['404' => $shared],
            '/d' => ['404' => $own], '/e' => ['404' => $own],
        ]),
    ]));

    expect(array_keys($doc['components']['responses']))->toBe(['Error404'])
        ->and($doc['components']['responses']['Error404']['description'])->toBe('Resource not found')
        ->and(responseRefAt($doc, '/d', '404'))->toBe('#/components/responses/Error404')
        // The words the component does not publish sit on the reference that overrides them…
        ->and($doc['paths']['/d']['get']['responses']['404']['description'])
        ->toBe('No taxonomy term matches the given slug')
        ->and($doc['paths']['/a']['get']['responses']['404'])->not->toHaveKey('description')
        // …and nothing was renamed, so nothing is reported.
        ->and($codes)->toBe([]);
});

it('leaves a shared response alone when an operation describes the same error in its own words', function (): void {
    // Locality, on the case that broke it: the arriving pair states the same status and the same body in
    // words of its own, and every byte the three that agree already published — their `$ref`s, the
    // component's name, its prose and the shape under it — has to survive that arrival untouched.
    $shared = ['404' => messageBody('Resource not found')];
    $own = ['404' => messageBody('No taxonomy term matches the given slug')];

    $before = errorDoc(['/a' => $shared, '/b' => $shared, '/c' => $shared]);
    $after = errorDoc(['/a' => $shared, '/b' => $shared, '/c' => $shared, '/d' => $own, '/e' => $own]);

    expect($after['paths']['/a'])->toBe($before['paths']['/a'])
        ->and($after['components']['responses'])->toBe($before['components']['responses'])
        ->and($after['components']['schemas'])->toBe($before['components']['schemas'])
        // …and the arrival really did arrive, with the words it wrote.
        ->and($after['paths']['/d']['get']['responses']['404']['description'])
        ->toBe('No taxonomy term matches the given slug');
});

it('publishes the words the most arms wrote, and hands the rest a reference that overrides them', function (array $responsesByPath, ?string $published, array $overriding): void {
    $doc = errorDoc($responsesByPath);

    $overrides = array_keys(array_filter(
        $responsesByPath,
        static fn (string $path): bool => isset($doc['paths'][$path]['get']['responses']['404']['description']),
        ARRAY_FILTER_USE_KEY,
    ));

    expect($doc['components']['responses']['Error404']['description'] ?? null)->toBe($published)
        ->and($overrides)->toBe($overriding);
})->with(function (): array {
    $silent = messageBody();
    unset($silent['description']);

    return [
        'every arm agrees' => [
            ['/a' => ['404' => messageBody('Not Found')], '/b' => ['404' => messageBody('Not Found')]],
            'Not Found',
            [],
        ],
        'an arm that says nothing takes the shared words' => [
            ['/a' => ['404' => messageBody('Not Found')], '/b' => ['404' => $silent]],
            'Not Found',
            [],
        ],
        'a plurality' => [
            ['/a' => ['404' => messageBody('Not Found')], '/b' => ['404' => messageBody('Not Found')], '/c' => ['404' => messageBody('Gone missing')]],
            'Not Found',
            ['/c'],
        ],
        'no plurality, settled by the wording itself' => [
            ['/a' => ['404' => messageBody('Not Found')], '/b' => ['404' => messageBody('Gone missing')]],
            'Gone missing',
            ['/a'],
        ],
        'a tie between two wordings two arms each state' => [
            [
                '/a' => ['404' => messageBody('Not Found')], '/b' => ['404' => messageBody('Not Found')],
                '/c' => ['404' => messageBody('Gone missing')], '/d' => ['404' => messageBody('Gone missing')],
            ],
            'Gone missing',
            ['/a', '/b'],
        ],
    ];
});

it('reads a response summary as prose about the body, like the description beside it', function (): void {
    // Both members a Reference Object may restate, so both come off the key and both travel with the arm
    // that wrote them.
    $summarised = static function (string $summary): array {
        $body = messageBody();
        $body = ['summary' => $summary] + $body;

        return $body;
    };

    $doc = errorDoc([
        '/a' => ['404' => $summarised('No such record')],
        '/b' => ['404' => $summarised('No such record')],
        '/c' => ['404' => $summarised('Nothing there')],
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error404'])
        ->and($doc['components']['responses']['Error404']['summary'])->toBe('No such record')
        ->and($doc['paths']['/c']['get']['responses']['404'])
        ->toBe(['$ref' => '#/components/responses/Error404', 'summary' => 'Nothing there', 'description' => 'Not Found']);
});

it('is still a no-op the second time it runs over a response that reworded one', function (): void {
    // The property that makes a rebuild over an emitted document byte-identical, on the shape this
    // rewrite introduces: an overriding reference is a reference, so the second run has nothing to hoist.
    $shared = ['404' => messageBody('Resource not found')];
    $own = ['404' => messageBody('No taxonomy term matches the given slug')];

    $once = errorDoc(['/a' => $shared, '/b' => $shared, '/c' => $own, '/d' => $own]);

    expect(transformedErrorDoc($once))->toBe($once);
});

it('emits an arm\'s own wording where the version defines it, and says so where it does not', function (string $version, Emitter $emitter, bool $carries): void {
    // A Reference Object states `summary` and `description` of its own in 3.1 and 3.2, and that is the
    // one place an arm's wording can live beside a shared body. 3.0 ignores anything beside a `$ref`, so
    // the export drops what it would ignore rather than shipping prose that reads as though it applied.
    $shared = ['404' => messageBody('Resource not found')];
    $own = ['404' => messageBody('No taxonomy term matches the given slug')];

    $doc = errorDoc(['/a' => $shared, '/b' => $shared, '/c' => $shared, '/d' => $own, '/e' => $own]);
    $document = UirDocument::fromArray(['openapi' => '3.2.0', 'info' => ['title' => 'API', 'version' => '1.0.0']] + $doc);
    $result = $emitter->emitWithReport($document);
    $emitted = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    expect($emitted['paths']['/d']['get']['responses']['404']['description'] ?? null)
        ->toBe($carries ? 'No taxonomy term matches the given slug' : null)
        ->and($emitted['paths']['/d']['get']['responses']['404']['$ref'])->toBe('#/components/responses/Error404')
        ->and($emitted['components']['responses']['Error404']['description'])->toBe('Resource not found')
        ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))
        ->toBe($carries ? [] : ['downlevel.ref-siblings', 'downlevel.ref-siblings']);
})->with([
    '3.2' => ['3.2', new OpenApi32Emitter, true],
    '3.1' => ['3.1', new OpenApi31DownlevelEmitter, true],
    '3.0' => ['3.0', new OpenApi30DownlevelEmitter, false],
]);

it('emits one illustration as the singular example every version defines', function (string $version, Emitter $emitter): void {
    // A one-entry map would cost a key nobody asked for, and `example` is what an unmerged document
    // already published — so the common case pays nothing for the rare one.
    $body = examplableBody(['code' => 'forbidden']);
    $doc = errorDoc(['/a' => ['403' => $body], '/b' => ['403' => $body]]);

    $document = UirDocument::fromArray(['openapi' => '3.2.0', 'info' => ['title' => 'API', 'version' => '1.0.0']] + $doc);
    $media = json_decode($emitter->emit($document), true, flags: JSON_THROW_ON_ERROR)['components']['responses']['Error403']['content']['application/problem+json'];

    expect($media['example'])->toBe(['code' => 'forbidden'])
        ->and($media)->not->toHaveKey('examples');
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
    // The response bucket mints names the same way the schema bucket does, and for the same reason. It
    // takes two genuinely different bodies to get here: arms differing only in how they illustrate one
    // body are one response, not a contest.
    $detail = ['description' => 'Forbidden', 'content' => ['application/problem+json' => ['schema' => ['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]]]]];

    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/c' => ['403' => $detail],
        '/d' => ['403' => $detail],
    ]);

    $names = array_keys($doc['components']['responses']);

    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('Error403')
        ->and($names)->not->toContain('Error403_2')
        ->and($names)->each->toMatch('/^Error403_[a-z2-7]{8}$/');
});

it('spends no name on arms that differ only in how they illustrate one body', function (): void {
    // The defect this collapse exists to fix, stated as the thing a consumer sees. Four operations, one
    // contract, two illustrations: without the collapse both arms claim `Error403`, both climb the ladder,
    // and an SDK consumer is handed two structurally identical types, neither named after anything.
    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden'])],
        '/c' => ['403' => examplableBody(['code' => 'denied'])],
        '/d' => ['403' => examplableBody(['code' => 'denied'])],
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['Error403'])
        ->and(array_keys($doc['components']['schemas']))->toBe(['Error403'])
        ->and(examplesAt($doc, '/a', '403'))->toHaveCount(2)
        ->and(responseRefAt($doc, '/a', '403'))->toBe(responseRefAt($doc, '/c', '403'));
});

it('names a response the same wherever the document meets it', function (): void {
    $one = ['403' => examplableBody(['code' => 'forbidden'])];
    $two = ['403' => examplableBody(['code' => 'forbidden'], 'You may not do that')];

    $first = errorDoc(['/a' => $one, '/b' => $one, '/c' => $two, '/d' => $two]);
    $second = errorDoc(['/a' => $two, '/b' => $two, '/c' => $one, '/d' => $one]);

    // One component either way, and reversing the walk moves neither the name nor which words it
    // publishes — so the arms that reword it are the same arms, wherever the walk met them.
    expect(array_keys($first['components']['responses']))->toBe(['Error403'])
        ->and(array_keys($second['components']['responses']))->toBe(['Error403'])
        ->and($first['components']['responses'])->toBe($second['components']['responses'])
        ->and($first['paths']['/a']['get']['responses']['403'])->toBe($second['paths']['/c']['get']['responses']['403'])
        ->and($first['paths']['/c']['get']['responses']['403'])->toBe($second['paths']['/a']['get']['responses']['403'])
        // …and one of the two really is rewording it, so the rows above are not equality between two
        // bare references.
        ->and($first['paths']['/c']['get']['responses']['403']['description'])->toBe('You may not do that')
        ->and($first['paths']['/a']['get']['responses']['403'])->not->toHaveKey('description');
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

it('names a hoisted body after the claim only where the claim describes it', function (string $case, array $body, array $schemas, array $responses): void {
    // A claim describes a response that states ONE representation, and the shape under it — the two
    // buckets then publish one concept under one name. Where the response offers several it says nothing
    // about which of them it named, so neither the response nor any shape in it may take that name; the
    // response asks for what it carries and falls to its status where a representation names no shape.
    $doc = errorDoc(['/a' => ['422' => $body], '/b' => ['422' => $body]]);

    expect(array_keys($doc['components']['schemas']))->toBe($schemas)
        ->and(array_keys($doc['components']['responses']))->toBe($responses);
})->with([
    ['a claim, one representation', claimedBody('ValidationError', messageBody('Unprocessable Entity')), ['ValidationError'], ['ValidationError']],
    ['a claim, two representations', claimedBody('ValidationError', twoRepresentationBody()), ['Error422'], ['Error422']],
    ['no claim, one representation', messageBody('Unprocessable Entity'), ['Error422'], ['Error422']],
    ['no claim, two representations', twoRepresentationBody(), ['Error422'], ['Error422']],
]);

it('keeps a response\'s claimed name off the representation beside the one it named', function (): void {
    // The reported defect, as a consumer met it: a renderer answering 422 with an RFC 9457 problem body
    // AND a challenge union had the union published as `components.schemas.ValidationError`, beside the
    // `components.responses.ValidationError` that correctly held the problem body. Two entries, one
    // name, different things — and the schema one was simply wrong about what it was.
    $body = claimedBody('ValidationError', twoRepresentationBody());

    $doc = errorDoc(['/a' => ['422' => $body], '/b' => ['422' => $body]]);

    // Nor does the name reach the response: the challenge union beside the problem body is a
    // representation `ValidationError` says nothing about, so the response is no more that error than
    // the union is. One of its two shapes is a name this run minted, so its status is what is left.
    expect(array_keys($doc['components']['responses']))->toBe(['Error422'])
        ->and(array_keys($doc['components']['schemas']))->toBe(['Error422'])
        ->and(schemaRefAt($doc, '/a', '422'))->toBe('#/components/schemas/Error422')
        // The named error's own body was already a reference, so this pass had nothing to hoist for it.
        ->and(responseAt($doc, '/a', '422')['content']['application/problem+json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/ProblemDetails']);
});

it('hoists one shape once when a claimed response and an unclaimed one state it', function (): void {
    // Also reported: the same union hoisted twice, once as `ValidationError` and once as `Error422`,
    // with identical members and two different ids. A claim that cannot name a shape must not scope it
    // either, or one anonymous body becomes two types a client has to tell apart.
    $claimed = claimedBody('ValidationError', twoRepresentationBody());
    $bare = twoRepresentationBody();

    $doc = errorDoc([
        '/a' => ['422' => $claimed], '/b' => ['422' => $claimed], '/c' => ['422' => $claimed],
        '/d' => ['422' => $bare],
    ]);

    // The same rule one level up, and the same reason: the claim describes neither of these responses,
    // so it does not tell them apart either. Two arms stating one body get one component, not two names
    // for one type — which is what asking a name that only one of them could keep would have cost.
    expect(array_keys($doc['components']['schemas']))->toBe(['Error422'])
        ->and(schemaRefAt($doc, '/a', '422'))->toBe('#/components/schemas/Error422')
        ->and(schemaRefAt($doc, '/d', '422'))->toBe('#/components/schemas/Error422')
        ->and(array_keys($doc['components']['responses']))->toBe(['Error422'])
        ->and(responseRefAt($doc, '/a', '422'))->toBe('#/components/responses/Error422')
        ->and(responseRefAt($doc, '/d', '422'))->toBe('#/components/responses/Error422');
});

it('leaves the plain claimed name to the body the claim describes', function (): void {
    // The reported regression, as a consumer met it: 75 operations answering 422 with an RFC 9457 problem
    // body and three answering it with that body PLUS an authentication challenge all inherited one
    // `ValidationError` from the renderer that named it, contested it, and were published as
    // `ValidationError_5lwwjnmg` and `ValidationError_m2hyrf57`. Nobody catches either of those.
    //
    // A response offering a representation the claim says nothing about is not the error the claim names,
    // so it does not ask for that name — which leaves the name to the body that IS it, and takes the
    // second body's name from the representations it actually carries.
    $problem = claimedBody('ValidationError', oneNamedRepresentationBody());
    $challenge = claimedBody('ValidationError', twoNamedRepresentationBody());

    $doc = errorDocWithSchemas([
        '/a' => ['422' => $problem], '/b' => ['422' => $problem], '/c' => ['422' => $problem],
        '/mfa' => ['422' => $challenge], '/magic' => ['422' => $challenge], '/verify' => ['422' => $challenge],
    ], [
        'ProblemDetailsData' => ['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]],
        'AuthenticationChallenge' => ['oneOf' => [['type' => 'object'], ['type' => 'object', 'properties' => ['sso' => ['type' => 'string']]]]],
    ]);

    expect(array_keys($doc['components']['responses']))
        ->toBe(['AuthenticationChallenge_ProblemDetailsData', 'ValidationError'])
        ->and(responseRefAt($doc, '/a', '422'))->toBe('#/components/responses/ValidationError')
        ->and(responseRefAt($doc, '/mfa', '422'))
        ->toBe('#/components/responses/AuthenticationChallenge_ProblemDetailsData')
        // No name in either bucket is a content hash, which is the whole complaint.
        ->and([...array_keys($doc['components']['responses']), ...array_keys($doc['components']['schemas'])])
        ->each->not->toMatch('/_[a-z2-7]{8}$/');
});

it('says nothing about a claim two bodies no longer contest', function (): void {
    // The collision warning told the reader to have the operations state one body, which they cannot: the
    // three genuinely answer with a representation the other seventy-five do not. With neither body asking
    // for a name the other holds there is no contest left to report, so the channel stays quiet.
    $problem = claimedBody('ValidationError', oneNamedRepresentationBody());
    $challenge = claimedBody('ValidationError', twoNamedRepresentationBody());

    $report = errorDocReport([
        'paths' => array_map(static fn (array $r): array => ['get' => ['responses' => $r]], [
            '/a' => ['422' => $problem], '/b' => ['422' => $problem],
            '/mfa' => ['422' => $challenge], '/magic' => ['422' => $challenge],
        ]),
        'components' => ['schemas' => [
            'ProblemDetailsData' => ['type' => 'object'],
            'AuthenticationChallenge' => ['type' => 'object'],
        ]],
    ]);

    expect($report)->toBe([]);
});

it('keeps a multi-representation response\'s name off the arrival and departure of the body beside it', function (): void {
    // Locality, both ways. The claimed single-representation pair names itself from its claim and the
    // multi-representation trio from the representations it carries, so neither is a function of the
    // other's existence — a document holding only one of them publishes the same name for it.
    $problem = claimedBody('ValidationError', oneNamedRepresentationBody());
    $challenge = claimedBody('ValidationError', twoNamedRepresentationBody());
    $schemas = ['ProblemDetailsData' => ['type' => 'object'], 'AuthenticationChallenge' => ['type' => 'object']];

    $both = errorDocWithSchemas([
        '/a' => ['422' => $problem], '/b' => ['422' => $problem],
        '/mfa' => ['422' => $challenge], '/magic' => ['422' => $challenge],
    ], $schemas);
    $onlyProblem = errorDocWithSchemas(['/a' => ['422' => $problem], '/b' => ['422' => $problem]], $schemas);
    $onlyChallenge = errorDocWithSchemas(['/mfa' => ['422' => $challenge], '/magic' => ['422' => $challenge]], $schemas);

    expect(responseRefAt($both, '/a', '422'))->toBe(responseRefAt($onlyProblem, '/a', '422'))
        ->and(responseRefAt($both, '/mfa', '422'))->toBe(responseRefAt($onlyChallenge, '/mfa', '422'))
        ->and($both['components']['responses']['ValidationError'])
        ->toBe($onlyProblem['components']['responses']['ValidationError'])
        ->and($both['components']['responses']['AuthenticationChallenge_ProblemDetailsData'])
        ->toBe($onlyChallenge['components']['responses']['AuthenticationChallenge_ProblemDetailsData']);
});

it('names a multi-representation body the same however the document spells it', function (): void {
    // A function of the set of representations and never of the walk: the media types state themselves in
    // either order, on either route, and one name comes out.
    $forward = claimedBody('ValidationError', twoNamedRepresentationBody());
    $backward = claimedBody('ValidationError', ['description' => 'Unprocessable Entity', 'content' => [
        'application/json' => ['schema' => ['$ref' => '#/components/schemas/AuthenticationChallenge']],
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetailsData']],
    ]]);
    $schemas = ['ProblemDetailsData' => ['type' => 'object'], 'AuthenticationChallenge' => ['type' => 'object']];

    $one = errorDocWithSchemas(['/a' => ['422' => $forward], '/b' => ['422' => $backward]], $schemas);
    $two = errorDocWithSchemas(['/a' => ['422' => $backward], '/b' => ['422' => $forward]], $schemas);

    // Insertion order inside the merged `content` is whichever arm the walk met first and is sorted away
    // on emit, so it is the EMITTED bytes the two documents owe each other.
    $emit = static fn (array $doc): string => (new OpenApi32Emitter)->emit(UirDocument::fromArray(
        ['uir' => '1.0', 'openapi' => '3.2.0', 'info' => ['title' => 'T', 'version' => '1'], 'x-docuccino' => ['id' => 'doc:default']] + $doc,
    ));

    expect(array_keys($one['components']['responses']))->toBe(['AuthenticationChallenge_ProblemDetailsData'])
        ->and(array_keys($two['components']['responses']))->toBe(['AuthenticationChallenge_ProblemDetailsData'])
        ->and($emit($one))->toBe($emit($two));
});

it('refuses a shape whose own name carries the separator the join is built from', function (): void {
    // Injectivity, which is the only reason the join uses a separator at all. A response whose
    // representations all resolve to ONE shape called `Auth_Challenge` spells exactly what a two-shape
    // join of `Auth` and `Challenge` spells, so exempting the one-shape case put both in one codomain:
    // alone, each published `Auth_Challenge`; together, they contested it and took
    // `Auth_Challenge_3blbosdq` and `Auth_Challenge_l2gsglvo`. A pair of hashes nobody catches by name,
    // and each moved because the other arrived — which is the outcome the separator exists to remove.
    //
    // Two representations resolving to one shape is what reaches this past a count of DISTINCT shapes.
    $schemas = [
        'Auth_Challenge' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        'Auth' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        'Challenge' => ['type' => 'object', 'properties' => ['c' => ['type' => 'string']]],
    ];

    $underscored = ['description' => 'Unprocessable Entity', 'content' => [
        'application/json' => ['schema' => ['$ref' => '#/components/schemas/Auth_Challenge']],
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Auth_Challenge']],
    ]];
    $join = ['description' => 'Unprocessable Entity', 'content' => [
        'application/json' => ['schema' => ['$ref' => '#/components/schemas/Auth']],
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Challenge']],
    ]];

    $alone = errorDocWithSchemas(['/a' => ['422' => $underscored], '/b' => ['422' => $underscored]], $schemas);
    $both = errorDocWithSchemas([
        '/a' => ['422' => $underscored], '/b' => ['422' => $underscored],
        '/m' => ['422' => $join], '/n' => ['422' => $join],
    ], $schemas);

    expect(array_keys($both['components']['responses']))->toBe(['Auth_Challenge', 'Error422'])
        // The `_`-bearing shape takes its status; the join keeps the name it spells unambiguously.
        ->and(responseRefAt($both, '/a', '422'))->toBe('#/components/responses/Error422')
        ->and(responseRefAt($both, '/m', '422'))->toBe('#/components/responses/Auth_Challenge')
        // Locality: neither name is a function of the other body being in the document.
        ->and(responseRefAt($alone, '/a', '422'))->toBe(responseRefAt($both, '/a', '422'))
        ->and(array_keys($both['components']['responses']))->each->not->toMatch('/_[a-z2-7]{8}$/');
});

it('orders a join by media type and not by the shapes\' own names', function (): void {
    // The order is the media types', which is a fact about the body rather than about the alphabet. Every
    // other case in this file uses shapes where the two orderings agree, so none of them would notice the
    // sort changing. Here they disagree — `application/json` sorts first and carries `Zebra` — and the
    // body spells its representations in a third order again, so neither the walk nor the shape names can
    // account for the answer.
    $schemas = [
        'Zebra' => ['type' => 'object', 'properties' => ['z' => ['type' => 'string']]],
        'Alpha' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
    ];
    $body = ['description' => 'Unprocessable Entity', 'content' => [
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Alpha']],
        'application/json' => ['schema' => ['$ref' => '#/components/schemas/Zebra']],
    ]];

    $doc = errorDocWithSchemas(['/a' => ['422' => $body], '/b' => ['422' => $body]], $schemas);

    expect(array_keys($doc['components']['responses']))->toBe(['Zebra_Alpha']);
});

it('lets a claim that names the whole response reach a multi-representation one', function (array $body, string $published): void {
    // The half the multi-representation rule must not swallow. A producer names the error it rendered and
    // cannot see what another producer put beside it, so its name does not describe the whole response —
    // something naming the response AT the operation can, and only it knows so. The claimer says which it
    // is; nothing a reader can compute off the finished response tells the two apart, and the layer least
    // of all, since `#[ErrorComponent]` on an exception class writes at `attribute` and speaks for one
    // body.
    $schemas = ['ProblemDetailsData' => ['type' => 'object'], 'AuthenticationChallenge' => ['type' => 'object']];

    $doc = errorDocWithSchemas(['/a' => ['422' => $body], '/b' => ['422' => $body]], $schemas);

    expect(array_keys($doc['components']['responses']))->toBe([$published])
        ->and(responseRefAt($doc, '/a', '422'))->toBe('#/components/responses/'.$published);
})->with([
    'names the whole response' => [
        fn (): array => wholeResponseBody('AuthenticationChallenge', twoNamedRepresentationBody()),
        'AuthenticationChallenge',
    ],
    // Everything else is a producer speaking for the body it built, however high it wrote: a claim
    // saying nothing, one at the top of the ladder, and one whose statement is not the `true` that
    // means it. All three read as a producer, which is the conservative half of the question.
    'says nothing' => [
        fn (): array => claimedBody('AuthenticationChallenge', twoNamedRepresentationBody()),
        'AuthenticationChallenge_ProblemDetailsData',
    ],
    'says nothing, at the config layer' => [
        fn (): array => claimedBody('AuthenticationChallenge', twoNamedRepresentationBody(), 'config'),
        'AuthenticationChallenge_ProblemDetailsData',
    ],
    'says something else' => [
        function (): array {
            $body = claimedBody('AuthenticationChallenge', twoNamedRepresentationBody());
            $body['x-docuccino']['facts']['componentNamesResponse'] = 'yes';

            return $body;
        },
        'AuthenticationChallenge_ProblemDetailsData',
    ],
]);

it('gives two responses carrying different shapes different names', function (): void {
    // Injectivity. Run together, `{Foo, BarBaz}` and `{FooBar, Baz}` both spell `FooBarBaz`, so two
    // responses with no shape in common contended for one name and each took a content-derived rung
    // neither needed — deterministic, and ambiguous for no reason.
    $representations = static fn (string $first, string $second): array => ['description' => 'Unprocessable Entity', 'content' => [
        'application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$first]],
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/'.$second]],
    ]];

    $left = $representations('BarBaz', 'Foo');
    $right = $representations('Baz', 'FooBar');
    $schemas = [
        'Foo' => ['type' => 'object'], 'BarBaz' => ['type' => 'string'],
        'FooBar' => ['type' => 'integer'], 'Baz' => ['type' => 'boolean'],
    ];

    $doc = errorDocWithSchemas([
        '/a' => ['422' => $left], '/b' => ['422' => $left],
        '/c' => ['422' => $right], '/d' => ['422' => $right],
    ], $schemas);

    $names = array_keys($doc['components']['responses']);

    expect($names)->toBe(['BarBaz_Foo', 'Baz_FooBar'])
        // Neither had to climb: a content-derived rung here would be the old ambiguity showing up as a
        // pair of hashes nobody can catch by name.
        ->and($names)->each->not->toMatch('/_[a-z2-7]{8}$/');
});

it('takes its status where a shape name would make the join ambiguous', function (): void {
    // `_` only splits back apart if no shape carries one, so a shape whose own name does is the case
    // this cannot name unambiguously — and a vague-but-true `Error422` beats a name that lies about
    // which shapes it was built from.
    $body = ['description' => 'Unprocessable Entity', 'content' => [
        'application/json' => ['schema' => ['$ref' => '#/components/schemas/Auth_Challenge']],
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetailsData']],
    ]];
    $schemas = ['Auth_Challenge' => ['type' => 'object'], 'ProblemDetailsData' => ['type' => 'object']];

    $doc = errorDocWithSchemas(['/a' => ['422' => $body], '/b' => ['422' => $body]], $schemas);

    expect(array_keys($doc['components']['responses']))->toBe(['Error422']);
});

it('keeps a lone shape name to itself, joining nothing', function (): void {
    // The name must not get uglier where there is nothing to disambiguate. Two media types answering
    // with ONE shape are one distinct shape, so the join has a single part and the separator never
    // appears — the shape keeps its own name exactly as it did before there was a separator at all.
    $body = ['description' => 'Unprocessable Entity', 'content' => [
        'application/json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetailsData']],
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetailsData']],
    ]];
    $schemas = ['ProblemDetailsData' => ['type' => 'object']];

    $doc = errorDocWithSchemas(['/a' => ['422' => $body], '/b' => ['422' => $body]], $schemas);

    expect(array_keys($doc['components']['responses']))->toBe(['ProblemDetailsData']);
});

it('keeps a whole-response name in the dedupe scope it names', function (): void {
    // The claim scopes exactly what it names. Two authors naming two different errors that happen to
    // carry the same representations get a component each; two bodies where only one was named do not
    // collapse onto one name, and the unnamed one publishes what it would have published alone.
    $named = wholeResponseBody('AuthenticationChallenge', twoNamedRepresentationBody());
    $other = wholeResponseBody('SignInIncomplete', twoNamedRepresentationBody());
    $schemas = ['ProblemDetailsData' => ['type' => 'object'], 'AuthenticationChallenge' => ['type' => 'object']];

    $doc = errorDocWithSchemas([
        '/a' => ['422' => $named], '/b' => ['422' => $named],
        '/c' => ['422' => $other], '/d' => ['422' => $other],
    ], $schemas);

    expect(array_keys($doc['components']['responses']))->toBe(['AuthenticationChallenge', 'SignInIncomplete'])
        ->and(responseRefAt($doc, '/a', '422'))->toBe('#/components/responses/AuthenticationChallenge')
        ->and(responseRefAt($doc, '/c', '422'))->toBe('#/components/responses/SignInIncomplete');
});

it('reports a whole-response name two different bodies contest, rather than picking one', function (): void {
    // An author's name is authoritative and still not magic: two different bodies asking for it is a
    // question only they can settle, and the ladder answers it the way it answers every other contest.
    $one = wholeResponseBody('AuthenticationChallenge', twoNamedRepresentationBody());
    $two = wholeResponseBody('AuthenticationChallenge', ['description' => 'Unprocessable Entity', 'content' => [
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetailsData']],
        'application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/AuthenticationChallenge']],
    ]]);

    $paths = ['paths' => array_map(static fn (array $r): array => ['get' => ['responses' => $r]], [
        '/a' => ['422' => $one], '/b' => ['422' => $one],
        '/c' => ['422' => $two], '/d' => ['422' => $two],
    ]), 'components' => ['schemas' => [
        'ProblemDetailsData' => ['type' => 'object'],
        'AuthenticationChallenge' => ['type' => 'object'],
    ]]];

    $names = array_keys(transformedErrorDoc($paths)['components']['responses']);
    $collision = array_values(array_filter(
        errorDocReport($paths),
        static fn ($d): bool => $d->code === 'components.name-collision',
    ));

    expect($names)->toHaveCount(2)
        ->and($names)->each->toMatch('/^AuthenticationChallenge_[a-z2-7]{8}$/')
        ->and($collision)->toHaveCount(1)
        // …and the help names both anchors that can settle it, and no action that cannot.
        ->and($collision[0]->help)->toContain('#[ErrorComponent]')
        ->and($collision[0]->help)->toContain('errorComponent: argument of the #[Response]')
        ->and($collision[0]->help)->not->toContain('state one body');
});

it('falls back to the status where a representation names no shape of its own', function (): void {
    // Degraded but true. A response is only named after the shapes it carries where every one of them is
    // a shape the document names — otherwise the name would speak for part of the body and say nothing
    // about the rest, which is the assertion this whole area exists to refuse.
    $body = claimedBody('ValidationError', ['description' => 'Unprocessable Entity', 'content' => [
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetailsData']],
        'application/json' => ['schema' => ['type' => 'object', 'properties' => ['otp' => ['type' => 'string']]]],
    ]]);

    $doc = errorDocWithSchemas(
        ['/a' => ['422' => $body], '/b' => ['422' => $body]],
        ['ProblemDetailsData' => ['type' => 'object']],
    );

    expect(array_keys($doc['components']['responses']))->toBe(['Error422'])
        ->and(responseRefAt($doc, '/a', '422'))->toBe('#/components/responses/Error422');
});

it('gives two anonymous representations of one status a name each, derived from their own content', function (): void {
    // The ladder's answer, unchanged: two shapes asking one name and neither keeping it. What the claim
    // no longer does is hand one of them the name and leave the other on the status.
    $body = claimedBody('ValidationError', [
        'description' => 'Unprocessable Entity',
        'content' => [
            'application/json' => ['schema' => ['type' => 'object', 'properties' => ['errors' => ['type' => 'object']]]],
            'application/vnd.api+json' => ['schema' => ['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]]],
        ],
    ]);

    $doc = errorDoc(['/a' => ['422' => $body], '/b' => ['422' => $body]]);
    $report = errorDocReport(['paths' => ['/a' => ['get' => ['responses' => ['422' => $body]]], '/b' => ['get' => ['responses' => ['422' => $body]]]]]);

    $help = array_values(array_filter(array_map(
        static fn (object $diagnostic): ?string => $diagnostic->code === 'components.name-collision' ? $diagnostic->help : null,
        $report,
    )));

    expect(array_keys($doc['components']['schemas']))->toHaveCount(2)
        ->and(array_keys($doc['components']['schemas']))->each->toMatch('/^Error422_[a-z2-7]{8}$/')
        // …and the reader is told why the name they declared did not reach either of them.
        ->and($help)->toHaveCount(1)
        ->and($help[0])->toContain('states one representation');
});

it('leaves a claimed body alone when a response stating several representations arrives', function (): void {
    // Locality across the narrowing: a route whose response offers two representations publishes a shape
    // and a response named after its status, and every byte the claimed single-representation pair
    // already published — its `$ref`s and both its components — survives that arrival untouched.
    $single = ['404' => claimedBody('NotFound', messageBody())];
    $arriving = ['404' => claimedBody('Missing', twoRepresentationBody('Not Found'))];

    $before = errorDoc(['/a' => $single, '/b' => $single]);
    $after = errorDoc(['/a' => $single, '/b' => $single, '/c' => $arriving, '/d' => $arriving]);

    expect(schemaRefAt($after, '/a', '404'))->toBe(schemaRefAt($before, '/a', '404'))
        ->and(responseRefAt($after, '/a', '404'))->toBe(responseRefAt($before, '/a', '404'))
        ->and($after['components']['schemas']['NotFound'])->toBe($before['components']['schemas']['NotFound'])
        ->and($after['components']['responses']['NotFound'])->toBe($before['components']['responses']['NotFound'])
        // …and the arrival really did arrive, under its status rather than under the name it declared.
        ->and(array_keys($after['components']['schemas']))->toBe(['NotFound', 'Error404'])
        ->and(array_keys($after['components']['responses']))->toBe(['NotFound', 'Error404']);
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

it('keeps two declared names that differ only by illustration apart, each with its own', function (): void {
    // Where the two features meet. Arms that only illustrate one body differently collapse onto one
    // component; a declaration is what says these are two errors, not two pictures of one — so the collapse
    // stops at the name, and neither component is handed the other's illustration.
    $one = claimedBody('TokenRejected', examplableBody(['code' => 'token']));
    $two = claimedBody('RegionBlocked', examplableBody(['code' => 'region']));

    $doc = errorDoc([
        '/a' => ['403' => $one], '/b' => ['403' => $one],
        '/c' => ['403' => $two], '/d' => ['403' => $two],
    ]);

    expect(array_keys($doc['components']['responses']))->toBe(['RegionBlocked', 'TokenRejected'])
        ->and(responseRefAt($doc, '/a', '403'))->toBe('#/components/responses/TokenRejected')
        ->and(responseRefAt($doc, '/c', '403'))->toBe('#/components/responses/RegionBlocked')
        // One illustration each, so each stays the singular `example` it arrived as.
        ->and(exampleAt($doc, '/a', '403'))->toBe(['code' => 'token'])
        ->and(exampleAt($doc, '/c', '403'))->toBe(['code' => 'region'])
        ->and(examplesAt($doc, '/a', '403'))->toBe([])
        ->and(examplesAt($doc, '/c', '403'))->toBe([]);
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
        ->and($collision[0]->message)->toContain($names[0])
        ->and($collision[0]->message)->toContain('More than one shared error body')
        // The remedy by name, and the shape of it: a name per body, since one spread over a family lands
        // right back here.
        ->and($collision[0]->help)->toContain('#[ErrorComponent]')
        ->and($collision[0]->help)->toContain('one name per body');
});

it('does not tell a reader a defaulted name was claimed', function (): void {
    // `Error404` is what a body is called when NOBODY named it, so a message about a name someone
    // "claimed" sends the author hunting through their code for a declaration that was never written.
    $one = messageBody();
    $two = ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]]]]];

    $collision = array_values(array_filter(
        errorDocReport(['paths' => array_map(static fn (array $r): array => ['get' => ['responses' => $r]], [
            '/a' => ['404' => $one], '/b' => ['404' => $one],
            '/c' => ['404' => $two], '/d' => ['404' => $two],
        ])]),
        static fn ($d): bool => $d->code === 'components.name-collision',
    ));

    expect($collision)->not->toBeEmpty()
        ->and($collision[0]->message)->toContain('"Error404"')
        ->and($collision[0]->message)->not->toContain('claim')
        // Where the default came from belongs in the help, which is where the author reads it.
        ->and($collision[0]->help)->toContain('named after its status');
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

it('says an incumbent holds the name when only one body wanted it', function (): void {
    // One body climbing past a component that already exists is not a contest between bodies, and telling
    // the reader several of them wanted the name would send them looking for the other one.
    $body = claimedBody('NotFound', messageBody());

    $collision = array_values(array_filter(
        errorDocReport([
            'paths' => [
                '/a' => ['get' => ['responses' => ['404' => $body]]],
                '/b' => ['get' => ['responses' => ['404' => $body]]],
            ],
            'components' => ['schemas' => ['NotFound' => ['type' => 'string']]],
        ]),
        static fn ($d): bool => $d->code === 'components.name-collision',
    ));

    expect($collision)->toHaveCount(1)
        ->and($collision[0]->message)->toContain('A component in components.schemas already holds the name "NotFound"')
        ->and($collision[0]->message)->not->toContain('More than one')
        ->and($collision[0]->help)->toContain('#[ErrorComponent]');
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

it('quotes a rejected name exactly as the document states it', function (): void {
    // The only names that reach this one are by definition ones nothing validated — an overlay states
    // `x-docuccino.facts.component` on whatever it likes, and the hoist reads the document. The
    // diagnostic quotes what it read, control characters and all: it travels to a JSON report and into
    // the emitted document, where `json_encode` escapes, and the terminal it may also reach escapes at
    // the write instead (`RendersDiagnostics`). Escaping here would garble both of those.
    $body = claimedBody("Evil\x1b[31m\nName", messageBody(), "acme\x07");
    $paths = ['paths' => [
        '/a' => ['get' => ['responses' => ['404' => $body]]],
        '/b' => ['get' => ['responses' => ['404' => $body]]],
    ]];

    $rejected = array_values(array_filter(errorDocReport($paths), static fn ($d): bool => $d->code === 'components.name-invalid'));

    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->message)->toContain("Evil\x1b[31m\nName")
        ->and($rejected[0]->message)->toContain("acme\x07")
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

it('publishes one illustration where a second says the same thing with a member filled in', function (): void {
    // A member filled from its declared type is not a value the server sends — it is what the build shows
    // where it read nothing. So an arm differing from another only where IT filled in illustrates no body
    // the other does not already illustrate, and publishing both advertises two shapes for one contract:
    // an SDK generator reads two examples as two variants, and the reader cannot tell which is real.
    $doc = errorDoc([
        '/a' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])],
    ]);

    // One illustration left, so there is no discriminator to mint and the singular member says it.
    expect(exampleAt($doc, '/a', '403'))->toBe(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])
        ->and(examplesAt($doc, '/a', '403'))->toBe([])
        ->and(array_keys($doc['components']['responses']))->toBe(['Error403']);
});

it('publishes both where two arms read two different values at one member', function (): void {
    // The rule is subsumption, never a score. `/a` read one member more than `/b`, so "keep the most
    // complete" would publish `/a` alone — and delete the only statement in the document that a
    // `denied` code is a body this contract has. Two arms that READ two different values at one member
    // are two variants, however much else one of them filled in.
    $doc = errorDoc([
        '/a' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])],
        '/b' => ['403' => filledBody(['code' => 'denied', 'hint' => 'string'], ['hint'])],
    ]);

    expect(exampleValuesAt($doc, '/a', '403'))->toBe([
        ['code' => 'forbidden', 'hint' => 'Ask an administrator.'],
        ['code' => 'denied', 'hint' => 'string'],
    ])->and(exampleAt($doc, '/a', '403'))->toBeNull();
});

it('publishes both where the member they differ on is one neither of them filled', function (): void {
    // Nothing was filled at `code`, so both arms read what they state and both statements are true of
    // the contract. This is the case the existing merge already got right, and the collapse must not
    // reach it.
    $doc = errorDoc([
        '/a' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint'])],
        '/b' => ['403' => filledBody(['code' => 'denied', 'hint' => 'string'], ['hint'])],
    ]);

    expect(exampleValuesAt($doc, '/a', '403'))->toBe([
        ['code' => 'forbidden', 'hint' => 'string'],
        ['code' => 'denied', 'hint' => 'string'],
    ]);
});

it('publishes both where both arms filled the member they differ on', function (): void {
    // Two fills of one member that came out differently mean the two arms were answering against
    // different schemas for it, which is a difference in the contract rather than in the illustration.
    $doc = errorDoc([
        '/a' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint'])],
        '/b' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 0], ['hint'])],
    ]);

    expect(exampleValuesAt($doc, '/a', '403'))->toHaveCount(2);
});

it('publishes both where one arm states a member the other does not', function (): void {
    // Different member sets are different bodies, and no fill makes one of them the other: dropping the
    // shorter would claim the contract always carries a member only one arm was seen to send.
    $doc = errorDoc([
        '/a' => ['403' => filledBody(['code' => 'string'], ['code'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])],
    ]);

    expect(exampleValuesAt($doc, '/a', '403'))->toHaveCount(2);
});

it('never drops an illustration whose producer recorded no fills, however placeholder-shaped it reads', function (): void {
    // The exemption, and why it is a FLAG rather than a reading of the value. `0` is exactly what this
    // pass fills an integer member with AND an ordinary thing for a server to send, so nothing looking at
    // the finished document can tell the two apart. An illustration arriving with no record of having
    // filled anything — an author's own example, a recorded body — is therefore one nothing may drop.
    $throttled = static fn (mixed $example): array => [
        'description' => 'Too Many Requests',
        'content' => ['application/problem+json' => [
            'schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'retryAfter' => ['type' => 'integer']]],
            'example' => $example,
        ]],
    ];

    $doc = errorDoc([
        '/a' => ['429' => $throttled(['code' => 'throttled', 'retryAfter' => 0])],
        '/b' => ['429' => $throttled(['code' => 'throttled', 'retryAfter' => 30])],
    ]);

    expect(exampleValuesAt($doc, '/a', '429'))->toHaveCount(2)
        ->and(exampleValuesAt($doc, '/a', '429'))->toContain(['code' => 'throttled', 'retryAfter' => 0])
        ->and(exampleValuesAt($doc, '/a', '429'))->toContain(['code' => 'throttled', 'retryAfter' => 30]);
});

it('drops that same illustration once its producer says it filled the member in', function (): void {
    // The other half of the row above, and the only difference between the two is the record: same bytes,
    // same schema, same members — one of them stated to be a fill and therefore covered by the value the
    // other read. Without this row the exemption would be indistinguishable from a collapse that never
    // fires at all.
    $throttled = static fn (mixed $example, array $filled = []): array => [
        'description' => 'Too Many Requests',
        'content' => ['application/problem+json' => [
            'schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'retryAfter' => ['type' => 'integer']]],
            'example' => $example,
        ]],
    ] + ($filled === [] ? [] : ['x-docuccino' => ['facts' => ['examplePlaceholders' => ['application/problem+json' => $filled]]]]);

    $doc = errorDoc([
        '/a' => ['429' => $throttled(['code' => 'throttled', 'retryAfter' => 0], ['retryAfter'])],
        '/b' => ['429' => $throttled(['code' => 'throttled', 'retryAfter' => 30])],
    ]);

    expect(exampleAt($doc, '/a', '429'))->toBe(['code' => 'throttled', 'retryAfter' => 30]);
});

it('reads a fill record as the same illustration however many arms restate it', function (): void {
    // The record belongs to the BODY, not to the arm: a third arm restating a body already there changes
    // nothing, and a body one arm filled and another READ is a body that was read. Otherwise the answer
    // would depend on which arms an application happens to have, which is the locality rule.
    $collapsed = errorDoc([
        '/a' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint'])],
        '/b' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint'])],
        '/c' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])],
    ]);

    // …and the same body reached once by a fill and once by a read is evidence, so it stays.
    $read = errorDoc([
        '/a' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'string'])],
        '/c' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])],
    ]);

    expect(exampleAt($collapsed, '/a', '403'))->toBe(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])
        ->and(exampleValuesAt($read, '/a', '403'))->toHaveCount(2);
});

it('keeps an illustration no surviving one covers, even where something dropped covered it', function (): void {
    // Subsumption is not transitive, and this is the shape that shows it: `/c` covers `/b`, `/b` covers
    // `/a`, and `/c` does NOT cover `/a` — it reads a `code` of its own that `/a` also read. Dropping
    // `/a` against a `/b` that is itself dropped would delete the only statement in the document that
    // `forbidden` is a code this contract answers with, so a body is only ever dropped against one that
    // survives.
    $doc = errorDoc([
        '/a' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint'])],
        '/b' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'], ['code'])],
        '/c' => ['403' => examplableBody(['code' => 'denied', 'hint' => 'Ask an administrator.'])],
    ]);

    $values = exampleValuesAt($doc, '/a', '403');

    expect($values)->toHaveCount(2)
        ->and($values)->toContain(['code' => 'forbidden', 'hint' => 'string'])
        ->and($values)->toContain(['code' => 'denied', 'hint' => 'Ask an administrator.']);
});

it('adds and removes only the arriving arm\'s own illustration', function (): void {
    // Locality on the blast radius the collapse changes. An arriving arm could only ever ADD a key; one
    // that READ a member another arm filled now removes that arm's key as well. What it must not do is
    // disturb an illustration neither of them is about, or move the component's name.
    $filled = filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint']);
    $unrelated = examplableBody(['code' => 'denied', 'hint' => 'Ask an administrator.']);

    $before = errorDoc(['/a' => ['403' => $filled], '/b' => ['403' => $unrelated]]);
    $after = errorDoc([
        '/a' => ['403' => $filled],
        '/b' => ['403' => $unrelated],
        '/c' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])],
    ]);

    $survivor = array_filter(
        examplesAt($before, '/a', '403'),
        static fn (array $example): bool => $example['value'] === ['code' => 'denied', 'hint' => 'Ask an administrator.'],
    );

    expect($survivor)->toHaveCount(1)
        ->and(examplesAt($before, '/a', '403'))->toHaveCount(2)
        ->and(examplesAt($after, '/a', '403'))->toHaveCount(2)
        // The unrelated arm's key and body are untouched, and the name every operation references is too.
        ->and(array_intersect_key(examplesAt($after, '/a', '403'), $survivor))->toBe($survivor)
        ->and(array_keys($after['components']['responses']))->toBe(['Error403'])
        ->and(responseRefAt($after, '/b', '403'))->toBe(responseRefAt($before, '/b', '403'));
});

it('collapses the same way whichever order the arms are met in', function (): void {
    // The choice has to be a function of the accumulated SET, or a warm fragment-cache build that met the
    // arms in another order would publish a different example from a cold one.
    $filled = filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint']);
    $read = examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.']);

    $forwards = errorDoc(['/a' => ['403' => $filled], '/b' => ['403' => $read]]);
    $backwards = errorDoc(['/a' => ['403' => $read], '/b' => ['403' => $filled]]);

    expect($forwards['components']['responses'])->toBe($backwards['components']['responses'])
        ->and(exampleAt($forwards, '/a', '403'))->toBe(['code' => 'forbidden', 'hint' => 'Ask an administrator.']);
});

it('keeps the arms an author named out of the collapse, and collapses the rest', function (): void {
    // A name is somebody saying this example is its own thing, so it is published as written whatever a
    // generated body beside it says. What the collapse settles is only the unnamed illustrations.
    $named = examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.']);
    $named['content']['application/problem+json']['examples'] = ['no-hint' => ['value' => ['code' => 'forbidden', 'hint' => 'string']]];
    unset($named['content']['application/problem+json']['example']);

    $doc = errorDoc([
        '/a' => ['403' => filledBody(['code' => 'forbidden', 'hint' => 'string'], ['hint'])],
        '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])],
        '/c' => ['403' => $named],
    ]);

    expect(array_keys(examplesAt($doc, '/a', '403')))->toBe(['example', 'no-hint'])
        ->and(examplesAt($doc, '/a', '403')['example']['value'])->toBe(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])
        ->and(examplesAt($doc, '/a', '403')['no-hint']['value'])->toBe(['code' => 'forbidden', 'hint' => 'string']);
});

it('walks past a fill record that is not the shape the fact is written in', function (): void {
    // An overlay or a hand-written document can put anything anywhere, so a malformed record is read as
    // no record at all — and an illustration nothing says was filled is one nothing may drop.
    $body = examplableBody(['code' => 'forbidden', 'hint' => 'string']);
    $body = ['x-docuccino' => ['facts' => ['examplePlaceholders' => ['application/problem+json' => 'hint']]]] + $body;

    $doc = errorDoc([
        '/a' => ['403' => $body],
        '/b' => ['403' => examplableBody(['code' => 'forbidden', 'hint' => 'Ask an administrator.'])],
    ]);

    expect(exampleValuesAt($doc, '/a', '403'))->toHaveCount(2);
});
