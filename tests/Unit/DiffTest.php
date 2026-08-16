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

it('escapes the algo version it quotes back in that refusal', function (): void {
    // The refusal names both versions and the command prints it straight to the terminal, so an id crafted
    // to carry an erase-line sequence rewrites the operator's log with a verdict the diff never reached.
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['x-docuccino']['id'] = "op:\x1B[2K\rALL CLEAR - 0 breaking changes:aaaa";

    $message = null;
    try {
        diffOf(diffBase(), $new);
    } catch (IncomparableDocumentsException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toBeString()
        ->and($message)->not->toContain("\x1B")
        ->and($message)->not->toContain("\r")
        ->and($message)->toContain('new=\x1B[2K\x0DALL CLEAR - 0 breaking changes.');
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

it('reports no API churn for an id-carrying artifact against the document it came from', function (): void {
    // The DEFAULT workflow: `docuccino:export` keeps ids, so the committed artifact is diffed against the
    // document it was emitted from. Everything the artifact carries an id for has to pair — parameters and
    // component schemas as much as operations. The content pages are the one real absence: OAS has nowhere
    // to put them.
    $changeset = diffOf(diffBaseArtifact(keepIds: true), diffBase());

    expect(array_keys(changesByCode($changeset)))->toBe(['page.added'])
        ->and($changeset->pairing)->toBe(Pairing::Identity);
});

it('pairs an artifact\'s parameters and component schemas by their flat ids', function (): void {
    // The flat id is the only identity an OAS artifact carries, and every node that mints one owes the same
    // pairing — so a renamed parameter and a renamed component stay cosmetic here too.
    $renamed = diffBase();
    $renamed['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['name'] = 'state';
    $renamed['components']['schemas']['FormPayload'] = $renamed['components']['schemas']['FormData'];
    unset($renamed['components']['schemas']['FormData']);

    $codes = array_keys(changesByCode(diffOf(diffBaseArtifact(keepIds: true), $renamed)));

    expect($codes)->not->toContain('parameter.removed')
        ->and($codes)->not->toContain('parameter.added')
        ->and($codes)->not->toContain('parameter.added-required')
        ->and($codes)->not->toContain('schema.removed')
        ->and($codes)->not->toContain('schema.added');
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

// --- Disjoint identities ----------------------------------------------------

it('names each kind of node the two sides share no identity for', function (): void {
    // Same algo version, ids that name nothing in common — an artifact diffed against another document's,
    // or one whose identity inputs moved. Everything under those kinds reads as removed AND re-added, so
    // the changeset is a phantom and has to say so.
    $old = diffBase();
    $old['components']['schemas']['FormMeta'] = [
        'x-docuccino' => ['id' => 'sch:v1:1111111111111111'],
        'type' => 'object',
    ];

    $new = $old;
    $new['components']['schemas']['FormData']['x-docuccino']['id'] = 'sch:v1:9999999999999999';
    $new['components']['schemas']['FormMeta']['x-docuccino']['id'] = 'sch:v1:2222222222222222';
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][0]['x-docuccino']['id'] = 'par:v1:8888888888888888';
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['x-docuccino']['id'] = 'par:v1:7777777777777777';

    $changeset = diffOf($old, $new);

    // The operations still pair, which is exactly why this can't be judged document-wide.
    expect($changeset->disjointIdentities)->toBe(['parameter', 'schema'])
        ->and($changeset->toArray()['disjointIdentities'])->toBe(['parameter', 'schema']);
});

it('holds its tongue when the identities do pair, or when there were none to pair', function (): void {
    expect(diffOf(diffBase(), diffBase())->disjointIdentities)->toBe([])
        // Structural pairing isn't keyed on ids at all, so a kind with none is no failure.
        ->and(diffOf(diffBaseArtifact(), diffBase())->disjointIdentities)->toBe([]);
});

it('does not call a kind disjoint when one side simply has none of it', function (): void {
    // Every component schema genuinely removed is a real diff, not a pairing failure.
    $new = diffBase();
    unset($new['components']);

    expect(diffOf(diffBase(), $new)->disjointIdentities)->toBe([]);
});

it('says nothing about a kind either side carries a single identity for', function (): void {
    // The document's only component schema, renamed and re-minted. "No id in common" here says only that
    // the one node changed — which is the commonest real diff there is — so calling it a pairing failure
    // would tell an operator to dismiss a genuine break.
    $new = diffBase();
    $new['components']['schemas']['FormPayload'] = $new['components']['schemas']['FormData'];
    $new['components']['schemas']['FormPayload']['x-docuccino']['id'] = 'sch:v1:9999999999999999';
    unset($new['components']['schemas']['FormData']);

    expect(diffOf(diffBase(), $new)->disjointIdentities)->toBe([]);
});

it('warns in the rendered output when a kind paired nothing', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][0]['x-docuccino']['id'] = 'par:v1:8888888888888888';
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['x-docuccino']['id'] = 'par:v1:7777777777777777';

    $rendered = (new ChangesetRenderer)->render(diffOf(diffBase(), $new));

    expect($rendered)->toContain('no parameter id appears on both')
        // Hedged, because a wholesale rewrite of every parameter looks identical from here.
        ->and($rendered)->toContain('usually a pairing failure rather')
        ->and($rendered)->toContain('re-export it');

    // …and stays quiet on a clean diff, so the warning keeps meaning something.
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

// --- Hoisted parameters ($ref parameters) ----------------------------------

/**
 * `diffBase()` with its two parameters hoisted into `components.parameters` and used by `$ref` — the
 * shape hand-written and third-party specs reach for, and one `docuccino:diff <file>` is handed.
 *
 * @param  list<string>  $used  component names the operation refs, in order
 * @return array<string, mixed>
 */
function diffHoistedParams(array $used = ['FormId', 'Status']): array
{
    $doc = diffBase();
    $inline = $doc['paths']['/api/v1/forms/{id}']['get']['parameters'];

    $doc['components']['parameters'] = ['FormId' => $inline[0], 'Status' => $inline[1]];
    $doc['paths']['/api/v1/forms/{id}']['get']['parameters'] = array_map(
        static fn (string $name): array => ['$ref' => '#/components/parameters/'.$name],
        $used,
    );

    return $doc;
}

it('reports a removed $ref parameter instead of losing it to a shared key', function (): void {
    // A Reference Object has no name and no `in`, so keying parameters on those collapses every one of
    // them in an operation onto a single entry and drops all but the last. A removed required parameter
    // then reads as no change at all — the one answer a diff must never give.
    $changes = changesByCode(diffOf(diffHoistedParams(), diffHoistedParams(['Status'])));

    expect($changes)->toHaveKey('parameter.removed')
        ->and($changes['parameter.removed']->breaking)->toBeTrue()
        ->and($changes['parameter.removed']->path)->toContain('path:id');
});

it('tells a $ref parameter from an inline one in the same operation', function (): void {
    $old = diffHoistedParams(['FormId']);
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][] = [
        'x-docuccino' => ['id' => 'par:v1:cccccccccccccccc'],
        'name' => 'status', 'in' => 'query', 'required' => false,
        'schema' => ['type' => 'string'],
    ];

    $new = $old;
    array_shift($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $removed = array_filter(diffOf($old, $new)->changes, static fn (Change $c): bool => $c->code === 'parameter.removed');

    expect($removed)->toHaveCount(1)
        ->and(array_values($removed)[0]->path)->toContain('path:id');
});

it('reads a $ref parameter as the parameter it points at', function (): void {
    // Hoisting is a move, not an API change: an inline parameter and a `$ref` to the same component are
    // the same parameter to a consumer, so the diff has to say nothing either way.
    expect(diffOf(diffBase(), diffHoistedParams())->isEmpty())->toBeTrue()
        ->and(diffOf(diffHoistedParams(), diffBase())->isEmpty())->toBeTrue();
});

/**
 * Two operations refing the same `components.parameters` entry.
 *
 * @param  array<string, mixed>  $component
 * @return array<string, mixed>
 */
function diffSharedParam(array $component): array
{
    $doc = diffHoistedParams(['FormId', 'Status']);
    $doc['components']['parameters']['Status'] = $component;
    $doc['paths']['/api/v1/forms']['get'] = [
        'x-docuccino' => ['id' => 'op:v1:3333333333333333'],
        'operationId' => 'forms.index',
        'parameters' => [['$ref' => '#/components/parameters/Status']],
        'responses' => ['200' => ['description' => 'Forms']],
    ];

    return $doc;
}

it('reports a change to a shared parameter against every operation that refs it', function (): void {
    $component = ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
    $required = $component;
    $required['required'] = true;

    $changeset = diffOf(diffSharedParam($component), diffSharedParam($required));
    $paths = array_map(
        static fn (Change $c): string => $c->path,
        array_values(array_filter($changeset->changes, static fn (Change $c): bool => $c->code === 'parameter.became-required')),
    );
    sort($paths);

    expect($changeset->isBreaking())->toBeTrue()
        ->and($paths)->toBe(['GET /api/v1/forms parameters query:status', 'GET /api/v1/forms/{id} parameters query:status']);
});

it('reports a shared parameter dropped from one operation against that operation only', function (): void {
    $component = ['name' => 'status', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];

    $new = diffSharedParam($component);
    $new['paths']['/api/v1/forms']['get']['parameters'] = [];

    $removed = array_filter(diffOf(diffSharedParam($component), $new)->changes, static fn (Change $c): bool => $c->code === 'parameter.removed');

    expect($removed)->toHaveCount(1)
        ->and(array_values($removed)[0]->path)->toBe('GET /api/v1/forms parameters query:status');
});

it('tells two parameter refs apart by pointer when neither side declares the component', function (): void {
    // Nothing to resolve through, so the pointer is the only thing left that distinguishes them — and it
    // is a good key in its own right: two uses of one pointer are one parameter.
    $dangling = diffHoistedParams();
    unset($dangling['components']['parameters']);

    $new = $dangling;
    array_pop($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $changes = changesByCode(diffOf($dangling, $new));

    expect(diffOf($dangling, $dangling)->isEmpty())->toBeTrue()
        ->and($changes)->toHaveKey('parameter.removed')
        ->and($changes['parameter.removed']->path)->toContain('#/components/parameters/Status');
});

it('lets what a $ref parameter states beside the pointer win over the component', function (): void {
    // A pointer is one use of a shared parameter, not the parameter, so anything stated next to it — its own
    // identity included — describes that use.
    $old = diffHoistedParams(['Status']);
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][0] += [
        'x-docuccino' => ['id' => 'par:v1:9999999999999999'],
        'description' => 'Filter by state',
    ];

    $new = $old;
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][0]['description'] = 'Filter by lifecycle state';

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('parameter.description-changed')
        ->and($changes['parameter.description-changed']->id)->toBe('par:v1:9999999999999999');
});

it('keys a parameter by a pointer it cannot resolve, whatever the pointer names', function (): void {
    // A `$ref` into another section, an empty component name, an external file: none resolve, and each is
    // still a parameter of its own rather than another copy of the last.
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'] = array_map(
        static fn (string $ref): array => ['$ref' => $ref],
        ['#/components/schemas/Status', '#/components/parameters/', 'shared.yaml#/Status'],
    );

    $new = $old;
    array_pop($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $removed = array_filter(diffOf($old, $new)->changes, static fn (Change $c): bool => $c->code === 'parameter.removed');

    expect(diffOf($old, $old)->isEmpty())->toBeTrue()
        ->and($removed)->toHaveCount(1)
        ->and(array_values($removed)[0]->path)->toContain('shared.yaml#/Status');
});

it('survives a $ref that is not even a string', function (): void {
    $malformed = diffBase();
    $malformed['paths']['/api/v1/forms/{id}']['get']['parameters'] = [['$ref' => ['nope']]];

    expect(diffOf($malformed, $malformed)->isEmpty())->toBeTrue();
});

it('counts a $ref parameter\'s identity as the component\'s when judging overlap', function (): void {
    // The warning that a kind paired nothing has to read identity the same way the pairing did, or it goes
    // quiet on exactly the pairing failure it exists to flag.
    $new = diffHoistedParams();
    $new['components']['parameters']['FormId']['x-docuccino']['id'] = 'par:v1:8888888888888888';
    $new['components']['parameters']['Status']['x-docuccino']['id'] = 'par:v1:7777777777777777';

    expect(diffOf(diffHoistedParams(), $new)->disjointIdentities)->toBe(['parameter']);
});

// --- Path-item parameters ---------------------------------------------------

/**
 * `diffBase()` with one parameter declared on the path item rather than the operation — the shape a
 * hand-written or third-party artifact reaches for when every operation under a path shares it. Docuccino
 * never writes it, so it only ever arrives from the side the diff did not build.
 *
 * @param  array<string, mixed>  $parameter
 * @return array<string, mixed>
 */
function diffPathItemParam(array $parameter): array
{
    $doc = diffBase();
    $doc['paths']['/api/v1/forms/{id}']['parameters'] = [$parameter];

    return $doc;
}

it('reports a required path-item parameter the new side no longer declares', function (): void {
    // A path item's parameters apply to every operation under it. Reading only the operation's own list
    // made a removed required parameter read as no change, which passes `--enforce` on a broken contract.
    $old = diffPathItemParam([
        'x-docuccino' => ['id' => 'par:v1:1111111111111111'],
        'name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'],
    ]);

    $changes = changesByCode(diffOf($old, diffBase()));

    expect($changes)->toHaveKey('parameter.removed')
        ->and($changes['parameter.removed']->breaking)->toBeTrue()
        ->and($changes['parameter.removed']->path)->toBe('GET /api/v1/forms/{id} parameters query:q');
});

it('compares a path-item parameter as a parameter of the operation under it', function (): void {
    $optional = ['x-docuccino' => ['id' => 'par:v1:1111111111111111'], 'name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
    $required = $optional;
    $required['required'] = true;

    $changes = changesByCode(diffOf(diffPathItemParam($optional), diffPathItemParam($required)));

    expect($changes)->toHaveKey('parameter.became-required')
        ->and($changes['parameter.became-required']->path)->toBe('GET /api/v1/forms/{id} parameters query:q');
});

it('applies a path item\'s parameter to every operation under it', function (): void {
    $old = diffPathItemParam([
        'x-docuccino' => ['id' => 'par:v1:1111111111111111'],
        'name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'],
    ]);
    $old['paths']['/api/v1/forms/{id}']['delete'] = [
        'x-docuccino' => ['id' => 'op:v1:3333333333333333'],
        'operationId' => 'forms.destroy',
        'responses' => ['204' => ['description' => 'Gone']],
    ];

    $new = $old;
    unset($new['paths']['/api/v1/forms/{id}']['parameters']);

    $paths = array_map(
        static fn (Change $c): string => $c->path,
        array_values(array_filter(diffOf($old, $new)->changes, static fn (Change $c): bool => $c->code === 'parameter.removed')),
    );
    sort($paths);

    expect($paths)->toBe(['DELETE /api/v1/forms/{id} parameters query:q', 'GET /api/v1/forms/{id} parameters query:q']);
});

it('lets an operation\'s own parameter replace the path item\'s of the same name and location', function (bool $hoisted): void {
    // OAS: an operation's entry for an `in` + `name` overrides the path item's, so the two are one
    // parameter and only the operation's counts. Reading the pointer form takes resolving it first.
    $shared = ['x-docuccino' => ['id' => 'par:v1:1111111111111111'], 'name' => 'status', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];

    $shadowed = diffBase();
    if ($hoisted) {
        $shadowed['components']['parameters'] = ['Status' => $shared];
        $shadowed['paths']['/api/v1/forms/{id}']['parameters'] = [['$ref' => '#/components/parameters/Status']];
    } else {
        $shadowed['paths']['/api/v1/forms/{id}']['parameters'] = [$shared];
    }

    expect(diffOf($shadowed, diffBase())->isEmpty())->toBeTrue();
})->with(['stated inline' => false, 'reached by $ref' => true]);

it('counts a path item\'s parameters when judging overlap', function (): void {
    // The warning that a kind paired nothing has to read the same parameters the pairing read, or a
    // document that declares them all on its path items looks to carry no parameter identity at all.
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['parameters'] = [
        ['x-docuccino' => ['id' => 'par:v1:1111111111111111'], 'name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']],
        ['x-docuccino' => ['id' => 'par:v1:2222222222222222'], 'name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
    ];
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'] = [];

    $new = $old;
    $new['paths']['/api/v1/forms/{id}']['parameters'][0]['x-docuccino']['id'] = 'par:v1:3333333333333333';
    $new['paths']['/api/v1/forms/{id}']['parameters'][1]['x-docuccino']['id'] = 'par:v1:4444444444444444';

    expect(diffOf($old, $new)->disjointIdentities)->toBe(['parameter']);
});

// --- Two nodes claiming one id ----------------------------------------------

it('reports a removed operation that shared its id with the one that stayed', function (): void {
    // Ids are read off an artifact nobody validated. Keyed on the id alone, the second node to claim one
    // overwrites the first and the node it hid leaves the comparison entirely.
    $old = diffBase();
    $old['paths']['/api/v1/forms'] = ['get' => [
        'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
        'operationId' => 'forms.index',
        'responses' => ['200' => ['description' => 'Forms']],
    ]];

    $new = $old;
    unset($new['paths']['/api/v1/forms']);

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('operation.removed')
        ->and($changes['operation.removed']->path)->toBe('GET /api/v1/forms')
        ->and($changes)->not->toHaveKey('operation.added');
});

it('reports a removed parameter that shared its id with the one that stayed', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][0]['x-docuccino']['id'] = 'par:v1:dupedupedupedupe';
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['x-docuccino']['id'] = 'par:v1:dupedupedupedupe';

    $new = $old;
    array_shift($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('parameter.removed')
        ->and($changes['parameter.removed']->breaking)->toBeTrue()
        ->and($changes['parameter.removed']->path)->toBe('GET /api/v1/forms/{id} parameters path:id')
        ->and($changes)->not->toHaveKey('parameter.added');
});

it('reports a removed component schema that shared its id with the one that stayed', function (): void {
    $old = diffBase();
    $old['components']['schemas']['FormSummary'] = [
        'x-docuccino' => ['id' => 'sch:v1:eeeeeeeeeeeeeeee'],
        'type' => 'object',
        'properties' => ['title' => ['type' => 'string']],
    ];

    $changes = changesByCode(diffOf($old, diffBase()));

    expect($changes)->toHaveKey('schema.removed')
        ->and($changes['schema.removed']->path)->toBe('components.schemas.FormSummary')
        ->and($changes)->not->toHaveKey('schema.added');
});

it('reports a removed content page that shared its id with the one that stayed', function (): void {
    $old = diffBase();
    $old['x-docuccino']['content']['pages'][] = [
        'id' => 'page:v1:ffffffffffffffff', 'slug' => 'deploying', 'title' => 'Deploying', 'content' => 'Ship it.',
    ];

    $changes = changesByCode(diffOf($old, diffBase()));

    expect($changes)->toHaveKey('page.removed')
        ->and($changes['page.removed']->path)->toBe('pages deploying')
        ->and($changes)->not->toHaveKey('page.added');
});

it('invents no churn when both sides carry the same contested id', function (): void {
    // What tells two nodes claiming one id apart has to be a function of the nodes, not of the order they
    // were met, or an unchanged pair of them reads as removed and re-added the moment one is reordered.
    $doc = diffBase();
    $doc['paths']['/api/v1/forms/{id}']['get']['parameters'][0]['x-docuccino']['id'] = 'par:v1:dupedupedupedupe';
    $doc['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['x-docuccino']['id'] = 'par:v1:dupedupedupedupe';

    $reordered = $doc;
    $reordered['paths']['/api/v1/forms/{id}']['get']['parameters'] = array_reverse($doc['paths']['/api/v1/forms/{id}']['get']['parameters']);

    expect(diffOf($doc, $doc)->isEmpty())->toBeTrue()
        ->and(diffOf($doc, $reordered)->isEmpty())->toBeTrue();
});

// --- Two nodes claiming one key ---------------------------------------------

it('tells a parameter from a decoy whose structural key spells that parameter\'s id', function (array $decoy): void {
    // An id and a structural key are two names for a node, not two key spaces: a hand-written entry whose
    // pointer — or whose `in` and `name` — spells another parameter's id lands on that parameter's key and
    // hides it. Shaped to match, the decoy answers every question asked of the parameter it hid, and a
    // parameter that became required reads as no change at all.
    $status = ['type' => 'string', 'enum' => ['draft', 'published', 'archived']];

    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][] = $decoy + ['required' => true, 'schema' => $status];

    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['required'] = true;

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('parameter.became-required')
        ->and($changes['parameter.became-required']->breaking)->toBeTrue()
        ->and($changes['parameter.became-required']->path)->toBe('GET /api/v1/forms/{id} parameters query:status')
        ->and($changes)->toHaveKey('parameter.removed');
})->with([
    'a pointer' => [['$ref' => 'par:v1:cccccccccccccccc']],
    'an in and a name' => [['in' => 'par:v1', 'name' => 'cccccccccccccccc']],
]);

it('tells a component schema from one whose id spells another\'s name', function (): void {
    $old = diffBase();
    $old['components']['schemas']['Legacy'] = ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]];
    $old['components']['schemas']['Shadow'] = ['x-docuccino-id' => 'name:Legacy', 'type' => 'string'];

    $new = $old;
    unset($new['components']['schemas']['Legacy']);

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('schema.removed')
        ->and($changes['schema.removed']->path)->toBe('components.schemas.Legacy')
        ->and($changes)->not->toHaveKey('schema.added');
});

it('tells a content page from one whose id spells another\'s slug', function (): void {
    $old = diffBase();
    $old['x-docuccino']['content']['pages'][] = ['slug' => 'intro', 'title' => 'Intro', 'content' => 'Start here.'];
    $old['x-docuccino']['content']['pages'][] = ['id' => 'slug:intro', 'slug' => 'shadow', 'title' => 'Shadow', 'content' => 'Hidden.'];

    $new = $old;
    unset($new['x-docuccino']['content']['pages'][1]);
    $new['x-docuccino']['content']['pages'] = array_values($new['x-docuccino']['content']['pages']);

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('page.removed')
        ->and($changes['page.removed']->path)->toBe('pages intro')
        ->and($changes)->not->toHaveKey('page.added');
});

it('reports a removed parameter that carried no id and shared its label', function (): void {
    // Two parameters of one operation may state the same `in` and `name` in an artifact nobody validated,
    // and with no id to key on the label is all there is.
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'] = [
        ['name' => 'token', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
        ['name' => 'token', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
    ];

    $new = $old;
    array_shift($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $changes = changesByCode(diffOf($old, $new));

    expect(diffOf($old, $old)->isEmpty())->toBeTrue()
        ->and($changes)->toHaveKey('parameter.removed')
        ->and($changes['parameter.removed']->breaking)->toBeTrue()
        ->and($changes)->not->toHaveKey('parameter.added');
});

it('reports a removed parameter that shared both its id and its label with the one that stayed', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'] = [
        ['x-docuccino' => ['id' => 'par:v1:dupedupedupedupe'], 'name' => 'token', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
        ['x-docuccino' => ['id' => 'par:v1:dupedupedupedupe'], 'name' => 'token', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
    ];

    $new = $old;
    array_shift($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $changes = changesByCode(diffOf($old, $new));

    expect(diffOf($old, $old)->isEmpty())->toBeTrue()
        ->and($changes)->toHaveKey('parameter.removed')
        ->and($changes)->not->toHaveKey('parameter.added');
});

// --- Pages with no slug to key on -------------------------------------------

/**
 * `diffBase()` with a page that states neither an id nor a slug — the shape a hand-written artifact
 * reaches for, and the only one the differ has to name for itself.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function diffAnonymousPage(array $extra = []): array
{
    $doc = diffBase();
    $doc['x-docuccino']['content']['pages'][] = ['title' => 'Rate limits', 'content' => 'Ten a second.'] + $extra;

    return $doc;
}

it('leaves a slug-less page alone when a page is inserted ahead of it', function (): void {
    // Keyed by where it sits in the list, a page nobody touched reads as removed and re-added the moment
    // another is written above it.
    $new = diffAnonymousPage();
    array_unshift($new['x-docuccino']['content']['pages'], [
        'id' => 'page:v1:1010101010101010', 'slug' => 'auth', 'title' => 'Authentication', 'content' => 'Use a token.',
    ]);

    $changes = changesByCode(diffOf(diffAnonymousPage(), $new));

    expect($changes)->toHaveKey('page.added')
        ->and($changes)->not->toHaveKey('page.removed')
        ->and($changes)->not->toHaveKey('page.content-changed');
});

it('keys a slug-less page by its title, so an edit to it still reads as an edit', function (): void {
    $new = diffAnonymousPage();
    $new['x-docuccino']['content']['pages'][1]['content'] = 'Twenty a second.';

    $changes = changesByCode(diffOf(diffAnonymousPage(), $new));

    expect($changes)->toHaveKey('page.content-changed')
        ->and($changes)->not->toHaveKey('page.removed');
});

it('keys a page with neither slug nor title by its content', function (): void {
    $old = diffBase();
    $old['x-docuccino']['content']['pages'][] = ['content' => 'Ten a second.'];
    $old['x-docuccino']['content']['pages'][] = ['content' => 'Tokens expire hourly.'];

    $new = $old;
    unset($new['x-docuccino']['content']['pages'][1]);
    $new['x-docuccino']['content']['pages'] = array_values($new['x-docuccino']['content']['pages']);

    $changes = changesByCode(diffOf($old, $new));

    expect(diffOf($old, $old)->isEmpty())->toBeTrue()
        ->and($changes)->toHaveKey('page.removed')
        ->and($changes)->not->toHaveKey('page.added')
        ->and($changes)->not->toHaveKey('page.content-changed');
});

it('invents no churn when both sides carry the same contested page id', function (): void {
    $doc = diffBase();
    $doc['x-docuccino']['content']['pages'][0]['id'] = 'page:v1:dupedupedupedupe';
    $doc['x-docuccino']['content']['pages'][] = [
        'id' => 'page:v1:dupedupedupedupe', 'slug' => 'auth', 'title' => 'Authentication', 'content' => 'Use a token.',
    ];

    $reordered = $doc;
    $reordered['x-docuccino']['content']['pages'] = array_reverse($doc['x-docuccino']['content']['pages']);

    expect(diffOf($doc, $doc)->isEmpty())->toBeTrue()
        ->and(diffOf($doc, $reordered)->isEmpty())->toBeTrue();
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

it('escapes control characters in text it read from an artifact', function (): void {
    // `docuccino:diff <file>` reads a document it did not write, and a removed node is described from that
    // side. Left as-is, an escape sequence or a newline in a name recolours the operator's terminal or
    // forges a line of the report — including the report's own verdict.
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['name'] = "status\x1B[31m\r\n  - [operation] GET /nope";
    $old['paths']['/api/v1/forms/{id}']['get']['summary'] = "Show a form\x1B]0;title\x07";

    $new = diffBase();
    array_pop($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $rendered = (new ChangesetRenderer)->render(diffOf($old, $new));

    expect($rendered)->not->toContain("\x1B")
        ->and($rendered)->not->toContain("\r")
        ->and($rendered)->toContain('status\x1B[31m\x0D\x0A  - [operation] GET /nope')
        ->and($rendered)->toContain('Show a form\x1B]0;title\x07')
        // The forged removal is text inside one real line, not a second one.
        ->and(substr_count($rendered, "\n  - "))->toBe(1);
});

it('leaves legitimate non-ASCII in artifact text alone', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['name'] = 'état';
    $old['paths']['/api/v1/forms/{id}']['get']['summary'] = 'Formulaire — 日本語';

    $new = diffBase();
    array_pop($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $rendered = (new ChangesetRenderer)->render(diffOf($old, $new));

    expect($rendered)->toContain('query:état')
        ->and($rendered)->toContain('Formulaire — 日本語');
});
