<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\ChangesetRenderer;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Diff\Pairing;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi32Emitter;

/**
 * @param  array<string, mixed>  $old
 * @param  array<string, mixed>  $new
 */
function diffOf(array $old, array $new): Changeset
{
    return (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));
}

/**
 * @return array<string, Change>
 */
function changesByCode(Changeset $changeset): array
{
    $out = [];
    foreach ($changeset->changes as $change) {
        $out[$change->code] = $change;
    }

    return $out;
}

it('reports no changes for an identical document', function (): void {
    expect(diffOf(diffBase(), diffBase())->isEmpty())->toBeTrue();
});

it('treats a path-parameter rename as cosmetic (same op id, no change)', function (): void {
    $new = diffBase();
    $paths = $new['paths'];
    $paths['/api/v1/forms/{formId}'] = $paths['/api/v1/forms/{id}'];
    unset($paths['/api/v1/forms/{id}']);
    $new['paths'] = $paths;

    expect(diffOf(diffBase(), $new)->isEmpty())->toBeTrue();
});

it('ignores provenance-only differences', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['x-docuccino']['provenance'] = [
        ['producer' => 'overlay', 'layer' => 'overlay', 'fields' => ['summary']],
    ];

    expect(diffOf(diffBase(), $new)->isEmpty())->toBeTrue();
});

it('refuses to diff documents built with different identity-algorithm versions', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['x-docuccino']['id'] = 'op:v2:aaaaaaaaaaaaaaaa';

    expect(fn () => diffOf(diffBase(), $new))->toThrow(IncomparableDocumentsException::class);
});

// --- Pairing ----------------------------------------------------------------

/**
 * `diffBase()` as a COMMITTED ARTIFACT: run through the real OAS emitter, which strips every
 * `x-docuccino` member (it carries provenance, which has no business in a published spec) unless asked
 * to keep node ids as a flat `x-docuccino-id`. This is what a user actually diffs against.
 *
 * @return array<string, mixed>
 */
function diffBaseArtifact(bool $keepIds = false): array
{
    $options = $keepIds ? (new EmitOptions)->withKeepIds() : new EmitOptions;
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((new OpenApi32Emitter)->emit(UirDocument::fromArray(diffBase()), $options), true);

    return $decoded;
}

it('reports no API churn for an emitted artifact against the document it came from', function (): void {
    // The advertised workflow: `docuccino:diff <committed OpenAPI artifact>` against a freshly built
    // document. The artifact carries no identities and the fresh document does, so pairing by id would put
    // the two sides in disjoint key spaces and report every operation removed AND re-added. The only thing
    // left is the content pages, which live under the document `x-docuccino` and genuinely cannot survive
    // OAS emission — a real absence, not a phantom one.
    $changeset = diffOf(diffBaseArtifact(), diffBase());

    expect(array_keys(changesByCode($changeset)))->toBe(['page.added'])
        ->and($changeset->pairing)->toBe(Pairing::Structural);
});

it('still finds a real change through structural pairing', function (): void {
    // Degrading must not blind the diff — only rename-detection is lost.
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['required'] = true;

    expect(array_keys(changesByCode(diffOf(diffBaseArtifact(), $new))))->toContain('parameter.became-required');
});

it('pairs by identity when the artifact kept its ids', function (): void {
    // What `docuccino:export` writes by default: each node id re-emitted flat, which is enough to pair
    // on — so the rename that reads as remove + add above stays cosmetic here.
    $renamed = diffBase();
    $renamed['paths']['/api/v1/forms/{formId}'] = $renamed['paths']['/api/v1/forms/{id}'];
    unset($renamed['paths']['/api/v1/forms/{id}']);

    $changeset = diffOf(diffBaseArtifact(keepIds: true), $renamed);

    expect($changeset->pairing)->toBe(Pairing::Identity)
        ->and(array_keys(changesByCode($changeset)))->not->toContain('operation.removed');
});

it('reads the flat id even where the nested member is gone', function (): void {
    // The emitter writes `x-docuccino-id`, never the nested object — so this is the only identity an
    // OpenAPI artifact can carry, and the differ has to know it.
    $artifact = diffBaseArtifact(keepIds: true);
    $operation = $artifact['paths']['/api/v1/forms/{id}']['get'];

    expect($operation)->toHaveKey('x-docuccino-id')
        ->and($operation)->not->toHaveKey('x-docuccino')
        ->and($operation['x-docuccino-id'])->toBe('op:v1:aaaaaaaaaaaaaaaa');
});

it('reports the pairing it used in toArray', function (): void {
    expect(diffOf(diffBase(), diffBase())->toArray()['pairing'])->toBe('identity')
        ->and(diffOf(diffBaseArtifact(), diffBase())->toArray()['pairing'])->toBe('structural');
});

it('renders a note when it had to pair structurally', function (): void {
    $rendered = (new ChangesetRenderer)->render(diffOf(diffBaseArtifact(), diffBase()));

    expect($rendered)->toContain('paired by method + path')
        ->and($rendered)->toContain('Re-export the artifact');

    // …and never when it paired by identity, so the note stays a signal.
    expect((new ChangesetRenderer)->render(diffOf(diffBase(), diffBase())))->toBe("No API changes.\n");
});

// --- Breaking rules ---------------------------------------------------------

it('classifies a removed operation as breaking', function (): void {
    $new = diffBase();
    $new['paths'] = [];

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('operation.removed');
    expect($changes['operation.removed']->breaking)->toBeTrue();
});

it('classifies a removed parameter as breaking', function (): void {
    $new = diffBase();
    array_pop($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('parameter.removed');
    expect($changes['parameter.removed']->breaking)->toBeTrue();
});

it('classifies a removed response status as breaking', function (): void {
    $new = diffBase();
    unset($new['paths']['/api/v1/forms/{id}']['get']['responses']['404']);

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('response.removed');
    expect($changes['response.removed']->breaking)->toBeTrue();
});

it('classifies a parameter becoming required as breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['required'] = true;

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('parameter.became-required');
    expect($changes['parameter.became-required']->breaking)->toBeTrue();
});

it('classifies an added required parameter as breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][] = [
        'x-docuccino' => ['id' => 'par:v1:9999999999999999'],
        'name' => 'tenant', 'in' => 'query', 'required' => true,
        'schema' => ['type' => 'string'],
    ];

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('parameter.added-required');
    expect($changes['parameter.added-required']->breaking)->toBeTrue();
});

it('classifies a narrowed type as breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['type' => ['string', 'integer']];
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['type' => 'string'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.type-narrowed');
    expect($changes['schema.type-narrowed']->breaking)->toBeTrue();
});

it('classifies a removed enum value as breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema']['enum'] = ['draft', 'published'];

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('schema.enum-value-removed');
    expect($changes['schema.enum-value-removed']->breaking)->toBeTrue();
});

it('classifies a required request property added as breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['put'] = [
        'x-docuccino' => ['id' => 'op:v1:1111111111111111'],
        'operationId' => 'forms.update',
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]]]],
        'responses' => ['200' => ['description' => 'ok']],
    ];
    $new = $old;
    $new['paths']['/api/v1/forms/{id}']['put']['requestBody']['content']['application/json']['schema']['required'] = ['title'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.required-added');
    expect($changes['schema.required-added']->breaking)->toBeTrue();
});

// --- Non-breaking set -------------------------------------------------------

it('classifies additions, widenings and prose edits as non-breaking', function (): void {
    $new = diffBase();
    // Added optional parameter.
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][] = [
        'x-docuccino' => ['id' => 'par:v1:8888888888888888'],
        'name' => 'include', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
    ];
    // Added enum value.
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema']['enum'] = ['draft', 'published', 'archived', 'trashed'];
    // Prose edit.
    $new['paths']['/api/v1/forms/{id}']['get']['summary'] = 'Show one form';
    // Added response.
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['500'] = ['description' => 'Server error'];
    // Deprecation.
    $new['paths']['/api/v1/forms/{id}']['get']['deprecated'] = true;
    // Added response-schema property.
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['createdAt'] = ['type' => 'string', 'format' => 'date-time'];

    $changeset = diffOf(diffBase(), $new);

    expect($changeset->isBreaking())->toBeFalse();

    $codes = array_keys(changesByCode($changeset));
    expect($codes)->toContain('parameter.added');
    expect($codes)->toContain('schema.enum-value-added');
    expect($codes)->toContain('operation.summary-changed');
    expect($codes)->toContain('response.added');
    expect($codes)->toContain('operation.deprecated-changed');
    expect($codes)->toContain('schema.property-added');
});

it('classifies a widened type as non-breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['type' => 'string'];
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['type' => ['string', 'integer']];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.type-widened');
    expect($changes['schema.type-widened']->breaking)->toBeFalse();
});

it('diffs content pages as non-breaking prose changes', function (): void {
    $new = diffBase();
    $new['x-docuccino']['content']['pages'][0]['content'] = 'Welcome, updated.';
    $new['x-docuccino']['content']['pages'][] = ['id' => 'page:v1:1010101010101010', 'slug' => 'auth', 'title' => 'Authentication', 'content' => 'Use a token.'];

    $changeset = diffOf(diffBase(), $new);

    expect($changeset->isBreaking())->toBeFalse();
    $codes = array_keys(changesByCode($changeset));
    expect($codes)->toContain('page.content-changed');
    expect($codes)->toContain('page.added');
});

it('classifies an added operation security requirement as breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['security'] = [['apiKey' => []]];

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('operation.security-added');
    expect($changes['operation.security-added']->breaking)->toBeTrue();
});

it('classifies a removed operation security requirement as non-breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['security'] = [['apiKey' => []]];

    $changes = changesByCode(diffOf($old, diffBase()));
    expect($changes)->toHaveKey('operation.security-removed');
    expect($changes['operation.security-removed']->breaking)->toBeFalse();
});

it('is insensitive to security scheme-map key order', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['security'] = [['apiKey' => [], 'oauth2' => ['read']]];
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['security'] = [['oauth2' => ['read'], 'apiKey' => []]];

    expect(diffOf($old, $new)->isEmpty())->toBeTrue();
});

it('classifies a property removed from a response schema as breaking', function (): void {
    $new = diffBase();
    unset($new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['title']);

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('schema.property-removed');
    expect($changes['schema.property-removed']->breaking)->toBeTrue();
});

it('classifies a required request property removed as non-breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['put'] = [
        'x-docuccino' => ['id' => 'op:v1:1111111111111111'],
        'operationId' => 'forms.update',
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]]]]],
        'responses' => ['200' => ['description' => 'ok']],
    ];
    $new = $old;
    unset($new['paths']['/api/v1/forms/{id}']['put']['requestBody']['content']['application/json']['schema']['properties']['title']);
    $new['paths']['/api/v1/forms/{id}']['put']['requestBody']['content']['application/json']['schema']['required'] = [];

    $changeset = diffOf($old, $new);
    $changes = changesByCode($changeset);
    expect($changes)->toHaveKey('schema.property-removed');
    expect($changes['schema.property-removed']->breaking)->toBeFalse();
});

it('classifies a format tightened on a request schema as breaking, but not on a response', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['put'] = [
        'x-docuccino' => ['id' => 'op:v1:1111111111111111'],
        'operationId' => 'forms.update',
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['at' => ['type' => 'string']]]]]],
        'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['at' => ['type' => 'string']]]]]]],
    ];

    // Request: format added → breaking.
    $reqNew = $old;
    $reqNew['paths']['/api/v1/forms/{id}']['put']['requestBody']['content']['application/json']['schema']['properties']['at']['format'] = 'date-time';
    $reqChanges = changesByCode(diffOf($old, $reqNew));
    expect($reqChanges)->toHaveKey('schema.format-changed');
    expect($reqChanges['schema.format-changed']->breaking)->toBeTrue();

    // Response: same format add → non-breaking.
    $resNew = $old;
    $resNew['paths']['/api/v1/forms/{id}']['put']['responses']['200']['content']['application/json']['schema']['properties']['at']['format'] = 'date-time';
    $resChanges = changesByCode(diffOf($old, $resNew));
    expect($resChanges)->toHaveKey('schema.format-changed');
    expect($resChanges['schema.format-changed']->breaking)->toBeFalse();
});

it('classifies a removed response media type as breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/xml'] = ['schema' => ['type' => 'object']];
    $new = diffBase();

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('response.content-removed');
    expect($changes['response.content-removed']->breaking)->toBeTrue();
});

it('classifies an added operation as non-breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['delete'] = [
        'x-docuccino' => ['id' => 'op:v1:2222222222222222'],
        'operationId' => 'forms.destroy',
        'responses' => ['204' => ['description' => 'Deleted']],
    ];

    $changeset = diffOf(diffBase(), $new);
    $changes = changesByCode($changeset);
    expect($changes)->toHaveKey('operation.added');
    expect($changes['operation.added']->breaking)->toBeFalse();
    expect($changeset->isBreaking())->toBeFalse();
});

it('classifies a parameter becoming optional as non-breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][0]['required'] = false;

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('parameter.became-optional');
    expect($changes['parameter.became-optional']->breaking)->toBeFalse();
});

// --- Hoisted error bodies ($ref responses) ---------------------------------

/**
 * The `diffBase()` document with its two 404-carrying operations' bodies hoisted into one
 * `components.responses` entry, exactly as the shared-error transformer writes it.
 *
 * @param  array<string, mixed>  $body  the shared 404 body
 * @return array<string, mixed>
 */
function diffHoisted404(array $body): array
{
    $doc = diffBase404();
    $doc['components']['responses'] = ['Error404' => $body];

    foreach ([['/api/v1/forms/{id}', 'get'], ['/api/v1/forms', 'get']] as [$path, $method]) {
        $doc['paths'][$path][$method]['responses']['404'] = [
            'x-docuccino' => $doc['paths'][$path][$method]['responses']['404']['x-docuccino'],
            '$ref' => '#/components/responses/Error404',
        ];
    }

    return $doc;
}

/**
 * `diffBase()` plus a second operation, both stating the same inline 404 body — the pre-hoist shape.
 *
 * @return array<string, mixed>
 */
function diffBase404(): array
{
    $body = [
        'description' => 'Not found',
        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]]],
    ];

    $doc = diffBase();
    $doc['paths']['/api/v1/forms/{id}']['get']['responses']['404'] = ['x-docuccino' => ['id' => 'res:v1:1111111111111111']] + $body;
    $doc['paths']['/api/v1/forms']['get'] = [
        'x-docuccino' => ['id' => 'op:v1:3333333333333333'],
        'operationId' => 'forms.index',
        'responses' => [
            '200' => ['description' => 'Forms'],
            '404' => ['x-docuccino' => ['id' => 'res:v1:2222222222222222']] + $body,
        ],
    ];

    return $doc;
}

it('reports nothing when an inline error body hoists to a shared component', function (): void {
    $inline = diffBase404();
    $hoisted = diffHoisted404($inline['paths']['/api/v1/forms']['get']['responses']['404']);
    unset($hoisted['components']['responses']['Error404']['x-docuccino']);

    expect(diffOf($inline, $hoisted)->isEmpty())->toBeTrue();
    expect(diffOf($hoisted, $inline)->isEmpty())->toBeTrue();
});

it('reports a change to a shared error body against every operation that refs it', function (): void {
    $old = diffHoisted404([
        'description' => 'Not found',
        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]]],
    ]);
    $new = diffHoisted404([
        'description' => 'Not found',
        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']]]]],
    ]);

    $changeset = diffOf($old, $new);
    $removed = array_filter($changeset->changes, static fn (Change $c): bool => $c->code === 'schema.property-removed');

    expect($changeset->isBreaking())->toBeTrue();
    expect($removed)->toHaveCount(2);
    $ids = array_map(static fn (Change $c): string => $c->id, array_values($removed));
    sort($ids);
    expect($ids)->toBe(['res:v1:1111111111111111', 'res:v1:2222222222222222']);
});

it('reports nothing when a shared error body only moves component name', function (): void {
    $body = [
        'description' => 'Not found',
        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]]],
    ];

    $old = diffHoisted404($body);
    $new = $old;
    $new['components']['responses'] = ['Error404_2' => $body];
    foreach ([['/api/v1/forms/{id}', 'get'], ['/api/v1/forms', 'get']] as [$path, $method]) {
        $new['paths'][$path][$method]['responses']['404']['$ref'] = '#/components/responses/Error404_2';
    }

    expect(diffOf($old, $new)->isEmpty())->toBeTrue();
});

it('reports nothing for a response ref naming a component neither side declares', function (): void {
    $dangling = diffHoisted404([]);
    unset($dangling['components']['responses']);

    expect(diffOf($dangling, $dangling)->isEmpty())->toBeTrue();
});

// --- Determinism, model and rendering --------------------------------------

it('produces a deterministic toArray with breaking-first ordering', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['summary'] = 'Changed';
    unset($new['paths']['/api/v1/forms/{id}']['get']['responses']['404']);

    $array = diffOf(diffBase(), $new)->toArray();

    expect($array['breaking'])->toBeTrue();
    expect($array['counts']['breaking'])->toBe(1);
    // First change is the breaking one.
    expect($array['changes'][0]['breaking'])->toBeTrue();
    expect($array['changes'][0]['code'])->toBe('response.removed');
});

it('renders a terminal report grouping breaking changes first', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['summary'] = 'Changed';
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['required'] = true;

    $rendered = (new ChangesetRenderer)->render(diffOf(diffBase(), $new));

    expect($rendered)->toContain('(1 breaking)');
    expect($rendered)->toContain('BREAKING');
    expect($rendered)->toContain('NON-BREAKING');
    expect(strpos($rendered, 'BREAKING'))->toBeLessThan(strpos($rendered, 'NON-BREAKING'));
    expect($rendered)->toContain('parameter.became-required');
});

it('renders a clean message when there are no changes', function (): void {
    expect((new ChangesetRenderer)->render(diffOf(diffBase(), diffBase())))->toBe("No API changes.\n");
});
