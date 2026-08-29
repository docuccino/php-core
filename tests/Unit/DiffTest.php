<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\ChangesetRenderer;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\FieldChange;
use Docuccino\Core\Diff\IdentityKeys;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Diff\Pairing;
use Docuccino\Core\Diff\Policy\VersioningPolicies;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\JsonValue;

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

/**
 * {@see diffBase()} with `FormData` made request-only: a PUT request body `$ref`s it and nothing
 * else reaches it, so the direction walk classes it as writer-side.
 *
 * @return array<string, mixed>
 */
function diffBaseWithRequestOnlyComponent(): array
{
    $doc = diffBase();
    $doc['paths']['/api/v1/forms/{id}']['put'] = [
        'x-docuccino' => ['id' => 'op:v1:1111111111111111'],
        'operationId' => 'forms.update',
        'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FormData']]]],
        'responses' => ['200' => ['description' => 'ok']],
    ];
    $doc['components']['schemas']['FormData']['properties']['status'] = ['type' => 'string', 'enum' => ['draft', 'published']];

    return $doc;
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
    // Through the shared reader: an associative decode reads `{}` back as `[]`, and at a compared
    // keyword those are two values — so the round trip alone would report a change nobody made.
    /** @var array<string, mixed> $decoded */
    $decoded = JsonValue::decode((new OpenApi32Emitter)->emit(UirDocument::fromArray(diffBase()), $options));

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

/**
 * `{}` is the schema an un-inferrable property gets, and a faithful read of a committed artifact hands
 * it over as the stdClass it was written as. The property walk tested `is_array()`, so it did not
 * degrade on one — it DROPPED the member, and then reported it added against a document that had it.
 */
it('reads a {} property schema as the empty schema it is, not as a missing property', function (): void {
    $document = static fn (mixed $displayName): array => [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['Widget' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'display_name' => $displayName],
        ]]],
    ];

    // The artifact's spelling on the left, the live draft's on the right. One document, both ways.
    expect(diffOf($document(JsonValue::decode('{}')), $document([]))->changes)->toBe([])
        ->and(diffOf($document([]), $document(JsonValue::decode('{}')))->changes)->toBe([]);

    // …and the property is genuinely being compared, not merely skipped on both sides: dropping it is
    // still a removal, and constraining it is still a change.
    $without = $document([]);
    unset($without['components']['schemas']['Widget']['properties']['display_name']);

    expect(array_keys(changesByCode(diffOf($document(JsonValue::decode('{}')), $without))))
        ->toBe(['schema.property-removed'])
        ->and(array_keys(changesByCode(diffOf($document(JsonValue::decode('{}')), $document(['type' => 'string'])))))
        ->not->toBe([]);
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

it('classifies an enum introduced as breaking on both sides', function (): void {
    // A closed set arriving where there was none rejects every value outside it, so a writer's valid
    // request stops being accepted; on a response the `request` flag can under-state the audience — a
    // shared component serves both directions — and standing this down green-lights exactly that.
    $old = diffBase();
    $new = $old;
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][0]['schema']['enum'] = [1, 2, 3];
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['title']['enum'] = ['a', 'b'];

    $paths = [];
    foreach (diffOf($old, $new)->changes as $change) {
        if ($change->code === 'schema.enum-added') {
            $paths[$change->path] = $change->breaking;
        }
    }

    expect($paths)->toBe([
        'GET /api/v1/forms/{id} parameters path:id schema.enum' => true,
        'GET /api/v1/forms/{id} responses 200 application/json schema.properties.title.enum' => true,
    ]);
});

it('classifies a response enum value added as breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['status'] = ['type' => 'string', 'enum' => ['draft', 'published']];
    $new = $old;
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['status']['enum'] = ['draft', 'published', 'archived'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.enum-value-added');
    expect($changes['schema.enum-value-added']->breaking)->toBeTrue();
});

it('classifies a response enum constraint dropped as breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['status'] = ['type' => 'string', 'enum' => ['draft', 'published']];
    $new = $old;
    unset($new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['status']['enum']);

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.enum-removed');
    expect($changes['schema.enum-removed']->breaking)->toBeTrue();
});

it('classifies a request enum constraint dropped as non-breaking', function (): void {
    $new = diffBase();
    unset($new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema']['enum']);

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('schema.enum-removed');
    expect($changes['schema.enum-removed']->breaking)->toBeFalse();
});

it('classifies an enum value added to a referenced component schema as breaking', function (): void {
    // Reached from a response, the component compares as one — the side where an enum addition
    // can break a reader.
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['form'] = ['$ref' => '#/components/schemas/FormData'];
    $old['components']['schemas']['FormData']['properties']['status'] = ['type' => 'string', 'enum' => ['draft', 'published']];
    $new = $old;
    $new['components']['schemas']['FormData']['properties']['status']['enum'] = ['draft', 'published', 'archived'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.enum-value-added');
    expect($changes['schema.enum-value-added']->breaking)->toBeTrue();
});

it('classifies an enum value added to a request-only component schema as non-breaking', function (): void {
    $old = diffBaseWithRequestOnlyComponent();
    $new = $old;
    $new['components']['schemas']['FormData']['properties']['status']['enum'] = ['draft', 'published', 'archived'];

    $changeset = diffOf($old, $new);

    expect($changeset->isBreaking())->toBeFalse();
    $changes = changesByCode($changeset);
    expect($changes)->toHaveKey('schema.enum-value-added');
    expect($changes['schema.enum-value-added']->breaking)->toBeFalse();
});

it('classifies a required property added to a request-only component schema as breaking', function (): void {
    // Request semantics cut both ways: writer-side, a new required property rejects every
    // existing client's body.
    $old = diffBaseWithRequestOnlyComponent();
    $new = $old;
    $new['components']['schemas']['FormData']['required'] = ['status'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.required-added');
    expect($changes['schema.required-added']->breaking)->toBeTrue();
});

it('classifies an enum value added to a component both sides reach as breaking', function (): void {
    $old = diffBaseWithRequestOnlyComponent();
    $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['form'] = ['$ref' => '#/components/schemas/FormData'];
    $new = $old;
    $new['components']['schemas']['FormData']['properties']['status']['enum'] = ['draft', 'published', 'archived'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes['schema.enum-value-added']->breaking)->toBeTrue();
});

it('classifies an enum value added to a webhook-referenced component schema as breaking', function (): void {
    // A webhook's payload sits in a requestBody, but the API consumer RECEIVES it — reader side.
    $old = diffBaseWithRequestOnlyComponent();
    unset($old['paths']['/api/v1/forms/{id}']['put']);
    $old['webhooks'] = [
        'form.created' => [
            'post' => [
                'x-docuccino' => ['id' => 'op:v1:1212121212121212'],
                'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FormData']]]],
                'responses' => ['200' => ['description' => 'ok']],
            ],
        ],
    ];
    $new = $old;
    $new['components']['schemas']['FormData']['properties']['status']['enum'] = ['draft', 'published', 'archived'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes['schema.enum-value-added']->breaking)->toBeTrue();
});

it('keeps the reader-side flag when the documents disagree on a component direction', function (): void {
    $old = diffBaseWithRequestOnlyComponent();
    $new = $old;
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['form'] = ['$ref' => '#/components/schemas/FormData'];
    $new['components']['schemas']['FormData']['properties']['status']['enum'] = ['draft', 'published', 'archived'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes['schema.enum-value-added']->breaking)->toBeTrue();
});

it('propagates request-only direction through nested component refs', function (): void {
    $old = diffBaseWithRequestOnlyComponent();
    $old['components']['schemas']['FormData']['properties']['meta'] = ['$ref' => '#/components/schemas/FormMeta'];
    $old['components']['schemas']['FormMeta'] = [
        'x-docuccino' => ['id' => 'sch:v1:3434343434343434'],
        'type' => 'object',
        'properties' => ['kind' => ['type' => 'string', 'enum' => ['a', 'b']]],
    ];
    $new = $old;
    $new['components']['schemas']['FormMeta']['properties']['kind']['enum'] = ['a', 'b', 'c'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.enum-value-added');
    expect($changes['schema.enum-value-added']->breaking)->toBeFalse();
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

it('reads a requirement an operation wrote without the list around it', function (): void {
    // Read the way `servers` and `tags` recover from a bare map — unwrapped — the scheme name never reaches
    // the comparison, and the report names a requirement that says nothing about which scheme it is.
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['security'] = ['apiKey' => []];

    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['security'] = ['oauth2' => ['read']];

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('operation.security-added')
        ->and($changes['operation.security-added']->breaking)->toBeTrue()
        ->and($changes['operation.security-added']->fields[0]->new)->toBe(['oauth2' => ['read']])
        ->and($changes['operation.security-removed']->fields[0]->old)->toBe(['apiKey' => []])
        ->and(diffOf($old, $old)->isEmpty())->toBeTrue();
});

it('is insensitive to security scheme-map key order', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['security'] = [['apiKey' => [], 'oauth2' => ['read']]];
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['security'] = [['oauth2' => ['read'], 'apiKey' => []]];

    expect(diffOf($old, $new)->isEmpty())->toBeTrue();
});

it('tells two requirements apart when JSON cannot spell either of them', function (): void {
    // A scope name that is not valid UTF-8 makes `json_encode()` answer false, and a fallback that
    // gave every un-encodable requirement one key would read the dropped scope as still present.
    $wide = ['oauth2' => ["read-\xB1"]];
    $narrow = ['oauth2' => ["write-\xB1"]];

    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['security'] = [$wide, $narrow];
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['security'] = [$wide];

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('operation.security-removed')
        ->and($changes['operation.security-removed']->fields[0]->old)->toBe($narrow)
        ->and($changes)->not->toHaveKey('operation.security-added')
        // …and the two are still ONE requirement each: neither side reports a change against itself.
        ->and(diffOf($old, $old)->isEmpty())->toBeTrue();
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

// --- Hoisted error SHAPES (the body in components.schemas) -----------------

/**
 * What the shared-error transformer's two passes actually leave: the body SHAPE under
 * `components.schemas`, the whole RESPONSE under `components.responses` pointing at it, and both
 * operations pointing at the response. The shape's id is minted the way the transformer mints it — from
 * the bytes it publishes — so every edit to the body re-mints it.
 *
 * @param  array<string, mixed>  $shape
 * @return array<string, mixed>
 */
function diffHoistedShape(array $shape): array
{
    $doc = diffHoisted404([
        'description' => 'Not found',
        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error404']]],
    ]);

    $doc['components']['schemas']['Error404'] =
        ['x-docuccino' => ['id' => (new IdentityGenerator)->publishedSchemaId('404:application/json', $shape)]] + $shape;

    return $doc;
}

/**
 * The shared error shape, with `code` the required property an edit takes away.
 *
 * @return array<string, mixed>
 */
function diffErrorShape(bool $withCode = true): array
{
    $properties = ['message' => ['type' => 'string']];

    if ($withCode) {
        $properties['code'] = ['type' => 'string'];
    }

    return [
        'type' => 'object',
        'required' => $withCode ? ['message', 'code'] : ['message'],
        'properties' => $properties,
    ];
}

it('calls dropping a required property from a hoisted error shape breaking', function (): void {
    // A schema published under a component name carries an id minted from the bytes it publishes, so the
    // edit re-mints it. Paired by identity alone the old body and the new one are two nodes that never meet:
    // one name yields a removal AND an addition, neither breaking, nothing compares the two bodies, and
    // `--enforce` passes a break to the error contract every operation in the document shares.
    $changes = changesByCode(diffOf(diffHoistedShape(diffErrorShape()), diffHoistedShape(diffErrorShape(withCode: false))));

    expect($changes)->toHaveKey('schema.property-removed')
        ->and($changes['schema.property-removed']->breaking)->toBeTrue()
        ->and($changes['schema.property-removed']->path)->toBe('components.schemas.Error404.properties.code')
        ->and($changes)->not->toHaveKey('schema.removed')
        ->and($changes)->not->toHaveKey('schema.added');
});

it('still reads a hoisted shape that only changed component name as a rename', function (): void {
    // Identity pairing stays primary, and this is the case only it can answer: same bytes, new name, so the
    // id is the same and the two are one schema. Read by name first, this would be a removal and an addition.
    $old = diffHoistedShape(diffErrorShape());

    $new = $old;
    $new['components']['schemas']['Problem'] = $old['components']['schemas']['Error404'];
    unset($new['components']['schemas']['Error404']);
    $new['components']['responses']['Error404']['content']['application/json']['schema']['$ref'] = '#/components/schemas/Problem';

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->not->toHaveKey('schema.removed')
        ->and($changes)->not->toHaveKey('schema.added')
        ->and($changes)->not->toHaveKey('schema.removed-still-referenced');
});

it('still reads a deleted schema and an unrelated new one as a removal and an addition', function (): void {
    // One schema left unpaired on each side is not a pair: they are re-paired by the name they publish
    // under, and these publish under two.
    $old = diffHoistedShape(diffErrorShape());

    $new = $old;
    unset($new['components']['schemas']['Error404']);
    $new['components']['responses']['Error404']['content']['application/json']['schema'] = diffErrorShape();
    $new['components']['schemas']['Envelope'] = [
        'x-docuccino' => ['id' => 'sch:v1:4444444444444444'],
        'type' => 'object',
        'properties' => ['data' => ['type' => 'string']],
    ];

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('schema.removed')
        ->and($changes['schema.removed']->path)->toBe('components.schemas.Error404')
        ->and($changes)->toHaveKey('schema.added')
        ->and($changes['schema.added']->path)->toBe('components.schemas.Envelope');
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

it('ignores what a $ref parameter states beside the pointer, bar the prose OAS allows there', function (): void {
    // OAS 3.1 §3.5: a Reference Object's members other than `summary` and `description` SHALL be ignored.
    // A `required: false` written beside a pointer at a component that says `required: true` describes
    // nothing, and reading it as the parameter's own reported it becoming optional — or, with the two sides
    // the other way round, breaking — for a contract that never moved. Identity is the exception: it names
    // the USE, which is what the diff pairs on, and it is ours rather than OAS's.
    $old = diffHoistedParams(['Status']);
    $old['components']['parameters']['Status']['required'] = true;
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][0] += [
        'x-docuccino' => ['id' => 'par:v1:9999999999999999'],
        'description' => 'Filter by state',
    ];

    $stated = $old;
    $stated['paths']['/api/v1/forms/{id}']['get']['parameters'][0] += [
        'required' => false,
        'deprecated' => true,
        'schema' => ['type' => 'integer'],
    ];

    $described = $old;
    $described['paths']['/api/v1/forms/{id}']['get']['parameters'][0]['description'] = 'Filter by lifecycle state';

    $changes = changesByCode(diffOf($old, $described));

    expect(diffOf($old, $stated)->isEmpty())->toBeTrue()
        ->and(diffOf($stated, $old)->isEmpty())->toBeTrue()
        ->and($changes)->toHaveKey('parameter.description-changed')
        ->and($changes['parameter.description-changed']->id)->toBe('par:v1:9999999999999999');
});

it('ignores a body stated beside a response $ref, and keeps the description', function (): void {
    // The same rule on the other node ComponentRefs resolves. A `content` beside a response pointer is
    // ignored by every generator reading the document, so reading it here reports a body change no client
    // will ever see.
    $body = [
        'description' => 'Not found',
        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]]],
    ];

    $old = diffHoisted404($body);

    $stated = $old;
    $stated['paths']['/api/v1/forms/{id}']['get']['responses']['404'] += [
        'content' => ['application/json' => ['schema' => ['type' => 'string']]],
        'headers' => ['X-Reason' => ['schema' => ['type' => 'string']]],
    ];

    $described = $old;
    $described['paths']['/api/v1/forms/{id}']['get']['responses']['404']['description'] = 'No such form';

    $changes = changesByCode(diffOf($old, $described));

    expect(diffOf($old, $stated)->isEmpty())->toBeTrue()
        ->and(diffOf($stated, $old)->isEmpty())->toBeTrue()
        ->and($changes)->toHaveKey('response.description-changed');
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

// --- Hoisted path items and request bodies ($ref) ---------------------------

/**
 * `diffBase()` with its one path hoisted into `components.pathItems` and reached by a `$ref` — the same
 * endpoint, spelled the way OAS lets a document share one. The name is escaped into the pointer, so a name
 * carrying a `/` or a `~` is still the name the pointer reaches.
 *
 * @return array<string, mixed>
 */
function diffHoistedPathItem(string $name = 'Form', string $token = 'Form'): array
{
    $doc = diffBase();
    $item = $doc['paths']['/api/v1/forms/{id}'];

    $doc['paths']['/api/v1/forms/{id}'] = ['$ref' => '#/components/pathItems/'.$token];
    $doc['components']['pathItems'] = [$name => $item];

    return $doc;
}

/**
 * `diffBase()` with its one path spelled as a pointer into ANOTHER document: unresolvable here, and no
 * evidence at all that the endpoint has gone — the case a pointer this document itself broke must not be
 * read as.
 *
 * @return array<string, mixed>
 */
function diffExternalPathItem(): array
{
    $doc = diffBase();
    $doc['paths']['/api/v1/forms/{id}'] = ['$ref' => 'shared.yaml#/pathItems/Form'];

    return $doc;
}

/**
 * `diffBase()` with a request body on its one operation: stated inline, or hoisted into
 * `components.requestBodies` and reached by a `$ref`. Tightened, it demands a property it used to accept
 * without — the commonest breaking edit a request body carries.
 *
 * @return array<string, mixed>
 */
function diffBodyDoc(bool $hoisted, bool $tightened = false): array
{
    $schema = ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]];

    if ($tightened) {
        $schema['required'] = ['title'];
    }

    $doc = diffBase();
    $body = ['content' => ['application/json' => ['schema' => $schema]]];

    if (! $hoisted) {
        $doc['paths']['/api/v1/forms/{id}']['get']['requestBody'] = $body;

        return $doc;
    }

    $doc['paths']['/api/v1/forms/{id}']['get']['requestBody'] = ['$ref' => '#/components/requestBodies/Form'];
    $doc['components']['requestBodies'] = ['Form' => $body];

    return $doc;
}

/**
 * `diffBase()` with two security schemes: one stated, and one reaching it through a `$ref` while writing a
 * description of its own beside the pointer.
 *
 * @return array<string, mixed>
 */
function diffHoistedScheme(string $description): array
{
    $doc = diffBase();
    $doc['components']['securitySchemes'] = [
        'Bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'The component says this.'],
        'Legacy' => ['$ref' => '#/components/securitySchemes/Bearer', 'description' => $description],
    ];

    return $doc;
}

it('reads a path item as the path item it points at', function (): void {
    // Hoisting a path is a move, not an API change, and the diff has to be the same either way round: read
    // as written, a pointer states no operations at all, so one side spelling the path that way reported
    // every operation and response under it removed — a release blocked over how the document was spelled.
    expect(diffOf(diffBase(), diffHoistedPathItem())->isEmpty())->toBeTrue()
        ->and(diffOf(diffHoistedPathItem(), diffBase())->isEmpty())->toBeTrue()
        ->and(diffOf(diffHoistedPathItem(), diffHoistedPathItem('Shared', 'Shared'))->isEmpty())->toBeTrue();

    // Emptiness is only worth what the comparison behind it was: unresolved, the operation is invisible and
    // all three of those pass while nothing is compared at all.
    $gone = diffHoistedPathItem();
    unset($gone['components']['pathItems']['Form']['get']);

    expect(changesByCode(diffOf(diffHoistedPathItem(), $gone)))->toHaveKey('operation.removed');
});

it('reports a break made behind a path item $ref', function (): void {
    // The worst answer this component can give. Both sides spell the path with a pointer, the operations
    // behind it are never read, and a response that stopped existing ships as "No API changes."
    $new = diffHoistedPathItem();
    unset($new['components']['pathItems']['Form']['get']['responses']['200']);

    $changeset = diffOf(diffHoistedPathItem(), $new);
    $changes = changesByCode($changeset);

    expect($changeset->isBreaking())->toBeTrue()
        ->and($changes)->toHaveKey('response.removed')
        ->and($changes['response.removed']->breaking)->toBeTrue()
        ->and($changes['response.removed']->path)->toBe('GET /api/v1/forms/{id} responses 200');
});

it('reads a webhook path item as the path item it points at', function (): void {
    // Webhooks are the same contract read the other way, and they are `$ref`ed the same way.
    $inline = diffWebhook();
    $hoisted = $inline;
    $hoisted['webhooks']['formSaved'] = ['$ref' => '#/components/pathItems/FormSaved'];
    $hoisted['components']['pathItems'] = ['FormSaved' => $inline['webhooks']['formSaved']];

    $broken = $hoisted;
    unset($broken['components']['pathItems']['FormSaved']['post']['responses']['200']);

    expect(diffOf($inline, $hoisted)->isEmpty())->toBeTrue()
        ->and(diffOf($hoisted, $inline)->isEmpty())->toBeTrue()
        ->and(changesByCode(diffOf($hoisted, $broken)))->toHaveKey('response.removed');
});

it('counts an identity behind a path item $ref when judging overlap', function (): void {
    // The warning that a kind paired nothing has to read a path item the way the pairing does, or a document
    // whose paths are pointers looks to carry no operation identity at all and it goes quiet on exactly the
    // pairing failure it exists to flag.
    $old = diffHoistedPathItem();
    $old['components']['pathItems']['Form']['put'] = [
        'x-docuccino' => ['id' => 'op:v1:1111111111111111'],
        'operationId' => 'forms.update',
        'responses' => ['200' => ['description' => 'Saved']],
    ];

    $new = $old;
    $new['components']['pathItems']['Form']['get']['x-docuccino']['id'] = 'op:v1:2222222222222222';
    $new['components']['pathItems']['Form']['put']['x-docuccino']['id'] = 'op:v1:3333333333333333';

    expect(diffOf($old, $new)->disjointIdentities)->toBe(['operation']);
});

it('reads a component name a pointer had to escape, whatever the bucket', function (callable $hoist): void {
    // `~1` and `~0` are how a name carrying a `/` or a `~` is spelled in a pointer. A resolver comparing the
    // raw text finds nothing and calls a perfectly resolvable reference dangling — which reads as the
    // endpoint, the body, the parameter or the response it names having gone away.
    [$inline, $hoisted, $edited] = $hoist('v1/forms~x', 'v1~1forms~0x');

    // The emptiness above is worth nothing on its own: it is also what a pointer nothing follows produces,
    // so the same pair has to report an edit made behind that escaped name.
    expect(diffOf($inline, $hoisted)->isEmpty())->toBeTrue()
        ->and(diffOf($hoisted, $inline)->isEmpty())->toBeTrue()
        ->and(diffOf($hoisted, $edited)->breakingChanges())->not->toBeEmpty();
})->with([
    'pathItems' => [static function (string $name, string $token): array {
        $hoisted = diffHoistedPathItem($name, $token);
        $edited = $hoisted;
        unset($edited['components']['pathItems'][$name]['get']['responses']['200']);

        return [diffBase(), $hoisted, $edited];
    }],
    'requestBodies' => [static function (string $name, string $token): array {
        $hoisted = diffBodyDoc(true);
        $hoisted['components']['requestBodies'] = [$name => $hoisted['components']['requestBodies']['Form']];
        $hoisted['paths']['/api/v1/forms/{id}']['get']['requestBody'] = ['$ref' => '#/components/requestBodies/'.$token];

        $edited = $hoisted;
        $edited['components']['requestBodies'][$name]['content']['application/json']['schema']['required'] = ['title'];

        return [diffBodyDoc(false), $hoisted, $edited];
    }],
    'parameters' => [static function (string $name, string $token): array {
        $inline = diffBase();
        $hoisted = $inline;
        $hoisted['components']['parameters'] = [$name => $inline['paths']['/api/v1/forms/{id}']['get']['parameters'][1]];
        $hoisted['paths']['/api/v1/forms/{id}']['get']['parameters'][1] = ['$ref' => '#/components/parameters/'.$token];

        $edited = $hoisted;
        $edited['components']['parameters'][$name]['required'] = true;

        return [$inline, $hoisted, $edited];
    }],
    'responses' => [static function (string $name, string $token): array {
        $inline = diffBase();
        $hoisted = $inline;
        $hoisted['components']['responses'] = [$name => $inline['paths']['/api/v1/forms/{id}']['get']['responses']['200']];
        $hoisted['paths']['/api/v1/forms/{id}']['get']['responses']['200'] = ['$ref' => '#/components/responses/'.$token];

        $edited = $hoisted;
        unset($edited['components']['responses'][$name]['content']['application/json']['schema']['properties']['title']);

        return [$inline, $hoisted, $edited];
    }],
]);

it('reports nothing where both documents point at the same path item it cannot follow', function (callable $break): void {
    // A pointer this differ cannot open is a comparison it cannot make — but two documents spelling one path
    // with one pointer are the same document there, and an unchanged document reports nothing, exactly as an
    // unresolved response or parameter `$ref` already does.
    $doc = $break(diffHoistedPathItem());

    expect(diffOf($doc, $doc)->isEmpty())->toBeTrue();
})->with([
    'a name the document does not declare' => [static function (array $doc): array {
        unset($doc['components']['pathItems']);

        return $doc;
    }],
    'a chain of pointers' => [static function (array $doc): array {
        $doc['components']['pathItems'] = [
            'Form' => ['$ref' => '#/components/pathItems/Other'],
            'Other' => $doc['components']['pathItems']['Form'],
        ];

        return $doc;
    }],
    'a cycle' => [static function (array $doc): array {
        $doc['components']['pathItems']['Form'] = ['$ref' => '#/components/pathItems/Form'];

        return $doc;
    }],
    'a pointer into another document' => [static function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}'] = ['$ref' => 'shared.yaml#/pathItems/Form'];

        return $doc;
    }],
    'a pointer at a member inside a component' => [static function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}'] = ['$ref' => '#/components/pathItems/Form/get'];

        return $doc;
    }],
]);

it('reports a path item $ref it cannot OPEN as a comparison it could not make', function (): void {
    // Both other answers claim knowledge the differ hasn't got. Every operation removed blames a pointer
    // into a document this resolver cannot open; nothing at all is the silence the pointer caused, dressed
    // as a pass. Non-breaking is honest only here, where the endpoint may be whole over there.
    $external = diffExternalPathItem();

    $changeset = diffOf(diffBase(), $external);
    $changes = changesByCode($changeset);

    expect($changeset->changes)->toHaveCount(1)
        ->and($changeset->isBreaking())->toBeFalse()
        ->and($changes)->toHaveKey('pathItem.unresolved-ref')
        ->and($changes['pathItem.unresolved-ref']->path)->toBe('/api/v1/forms/{id}')
        ->and($changes['pathItem.unresolved-ref']->fields[0]->toArray())
        ->toBe(['field' => '$ref', 'old' => null, 'new' => 'shared.yaml#/pathItems/Form']);

    $back = changesByCode(diffOf($external, diffBase()));

    expect($back)->toHaveKey('pathItem.unresolved-ref')
        ->and($back['pathItem.unresolved-ref']->fields[0]->toArray())
        ->toBe(['field' => '$ref', 'old' => 'shared.yaml#/pathItems/Form', 'new' => null]);
});

it('tells a path item $ref this document BROKE from one it cannot open', function (): void {
    // The two reach the same dead end and mean opposite things. A local pointer at a `components.pathItems`
    // name the document does not declare publishes nothing at that path for any reader of it — the
    // operations, their parameters, their response schemas and their authentication requirement are all
    // gone — and that is what renaming or dropping that entry leaves behind. Answered as one with the
    // pointer into another file, a removed endpoint passed `assertNoBreakingChanges()` and asked its
    // versioning policy for no major bump.
    $undeclared = diffHoistedPathItem();
    unset($undeclared['components']['pathItems']);

    $broken = diffOf(diffBase(), $undeclared);
    $unopenable = diffOf(diffBase(), diffExternalPathItem());

    expect(changesByCode($broken))->toHaveKey('pathItem.unresolved-ref')
        ->and($broken->isBreaking())->toBeTrue()
        ->and(changesByCode($unopenable))->toHaveKey('pathItem.unresolved-ref')
        ->and($unopenable->isBreaking())->toBeFalse();
});

it('answers every spelling of a path that publishes no operation the same way', function (callable $blank): void {
    // All three publish the same contract — no operation at that path — so a gate that stops two of them
    // and passes the third is keying on how the document was spelled rather than on what it says. The
    // pointer was the one that passed.
    expect(diffOf(diffBase(), $blank())->isBreaking())->toBeTrue();
})->with([
    'an empty path item' => [static function (): array {
        $doc = diffBase();
        $doc['paths']['/api/v1/forms/{id}'] = [];

        return $doc;
    }],
    'a path item stating nothing the differ models' => [static function (): array {
        $doc = diffBase();
        $doc['paths']['/api/v1/forms/{id}'] = ['summary' => 'Forms'];

        return $doc;
    }],
    'a local pointer at a name the document does not declare' => [static function (): array {
        $doc = diffHoistedPathItem();
        unset($doc['components']['pathItems']);

        return $doc;
    }],
]);

it('charges a broken path item pointer to the side that broke it', function (): void {
    // Only what the NEW document publishes can be lost: one already broken there had nothing left to break,
    // and repairing one is not a breaking change — a gate that fails the fix is a gate people switch off.
    $undeclared = diffHoistedPathItem();
    unset($undeclared['components']['pathItems']);

    $renamed = $undeclared;
    $renamed['paths']['/api/v1/forms/{id}'] = ['$ref' => '#/components/pathItems/Other'];

    $moved = diffOf($undeclared, $renamed);

    expect(diffOf($undeclared, diffBase())->isBreaking())->toBeFalse()
        ->and(changesByCode($moved))->toHaveKey('pathItem.unresolved-ref')
        ->and($moved->isBreaking())->toBeFalse();
});

it('tells a chain off a declared name from a name that was never declared', function (): void {
    // Same pointer text, two different answers: one document declares the name and chains off it, which is
    // this resolver stopping one hop in, and the other declares nothing at all, which is the document being
    // broken. Compared as pointer text alone the two read as the same document there, and the break is lost.
    $chained = diffHoistedPathItem();
    $chained['components']['pathItems'] = [
        'Form' => ['$ref' => '#/components/pathItems/Other'],
        'Other' => $chained['components']['pathItems']['Form'],
    ];

    $undeclared = diffHoistedPathItem();
    unset($undeclared['components']['pathItems']);

    $changeset = diffOf($chained, $undeclared);

    expect(changesByCode($changeset))->toHaveKey('pathItem.unresolved-ref')
        ->and($changeset->isBreaking())->toBeTrue();
});

it('reads a request body as the body it points at', function (): void {
    // Read as written a pointer states no `content`, which produces this same emptiness while comparing
    // nothing — so the pair has to report an edit made behind the pointer as well.
    expect(diffOf(diffBodyDoc(false), diffBodyDoc(true))->isEmpty())->toBeTrue()
        ->and(diffOf(diffBodyDoc(true), diffBodyDoc(false))->isEmpty())->toBeTrue()
        ->and(diffOf(diffBodyDoc(true), diffBodyDoc(true, true))->breakingChanges())->not->toBeEmpty();
});

it('reports a request body tightened behind a $ref', function (bool $oldHoisted, bool $newHoisted): void {
    // A pointer read as a body states no `content`, so the media-type walk ran over nothing at all and a body
    // that stopped accepting what every client sends reported no breaking change.
    $changeset = diffOf(diffBodyDoc($oldHoisted), diffBodyDoc($newHoisted, true));
    $changes = changesByCode($changeset);

    expect($changeset->isBreaking())->toBeTrue()
        ->and($changes)->toHaveKey('schema.required-added')
        ->and($changes['schema.required-added']->breaking)->toBeTrue();
})->with([
    'both sides hoisted' => [true, true],
    'hoisted then inline' => [true, false],
    'inline then hoisted' => [false, true],
]);

it('reports a request body $ref it cannot OPEN as a comparison it could not make', function (): void {
    $external = diffBodyDoc(true);
    $external['paths']['/api/v1/forms/{id}']['get']['requestBody'] = ['$ref' => 'shared.yaml#/requestBodies/Form'];

    $changeset = diffOf(diffBodyDoc(false), $external);
    $changes = changesByCode($changeset);

    expect($changeset->changes)->toHaveCount(1)
        ->and($changeset->isBreaking())->toBeFalse()
        ->and($changes)->toHaveKey('requestBody.unresolved-ref')
        ->and($changes['requestBody.unresolved-ref']->path)->toBe('GET /api/v1/forms/{id} requestBody')
        ->and(diffOf($external, $external)->isEmpty())->toBeTrue();
});

it('reports a request body behind a $ref this document broke as breaking', function (): void {
    // The walk returns before the media types, so what the body now demands is unknown — and a body no
    // reader can open is not one a client can still send. The tightening it hides here is the same edit
    // reported breaking while the component was still declared.
    $undeclared = diffBodyDoc(true, true);
    unset($undeclared['components']['requestBodies']);

    $changeset = diffOf(diffBodyDoc(false), $undeclared);

    expect(changesByCode($changeset))->toHaveKey('requestBody.unresolved-ref')
        ->and($changeset->isBreaking())->toBeTrue()
        ->and(diffOf($undeclared, diffBodyDoc(false))->isBreaking())->toBeFalse()
        ->and(diffOf($undeclared, $undeclared)->isEmpty())->toBeTrue();
});

it('takes a description from beside the $ref and the contract from the component', function (callable $case): void {
    // OAS gives a Reference Object two members of its own beside the pointer — `summary` and `description`
    // — says they override the component's, and says every other sibling is ignored. Two of these resolvers
    // read that the other way round, so a description written at the referring site was invisible: editing
    // one reported nothing at all, at a position whose prose the diff otherwise reports.
    [$old, $new, $code] = $case();

    expect(changesByCode(diffOf($old, $new)))->toHaveKey($code);
})->with([
    'a parameter' => [static function (): array {
        $old = diffBase();
        $param = $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1];
        $param['description'] = 'The component says this.';
        $old['components']['parameters'] = ['Status' => $param];
        $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1] = ['$ref' => '#/components/parameters/Status', 'description' => 'Which forms to return.'];

        $new = $old;
        $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['description'] = 'Which forms to return, by state.';

        return [$old, $new, 'parameter.description-changed'];
    }],
    'a response' => [static function (): array {
        $old = diffBase();
        $old['components']['responses'] = ['Form' => $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']];
        $old['paths']['/api/v1/forms/{id}']['get']['responses']['200'] = ['$ref' => '#/components/responses/Form', 'description' => 'The form.'];

        $new = $old;
        $new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['description'] = 'The form, as stored.';

        return [$old, $new, 'response.description-changed'];
    }],
    'a security scheme' => [static function (): array {
        $old = diffHoistedScheme('Send the bearer token.');
        $new = diffHoistedScheme('Send the bearer token in the Authorization header.');

        return [$old, $new, 'securityScheme.description-changed'];
    }],
]);

it('ignores a member beside a $ref that is not the referring node\'s to state', function (): void {
    // The other half of the same OAS rule, and the reason all four resolvers read the contract off the
    // component: a `type` written beside a pointer describes nothing, and honouring it reports the way into
    // the API changing for a scheme that has not moved.
    $stated = diffBase();
    $stated['components']['securitySchemes'] = [
        'Bearer' => ['type' => 'http', 'scheme' => 'bearer'],
        'Legacy' => ['$ref' => '#/components/securitySchemes/Bearer'],
    ];

    $overreaching = $stated;
    $overreaching['components']['securitySchemes']['Legacy'] += ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Token'];

    expect(diffOf($stated, $overreaching)->isEmpty())->toBeTrue();
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

it('keeps every node when a qualifier spells a key another node claims outright', function (array $entries): void {
    // Stated in the keys themselves, because this is the one thing the class exists to guarantee and the
    // crafted collision is invisible from the document side. Qualifying used to write further into the key
    // space it was escaping: `X` qualified with `S` spells the id `X#S`, and the last qualifier is never
    // re-checked, so the node it landed on left the comparison and its removal was reported as no change.
    [$keyed] = IdentityKeys::pair($entries, []);

    $nodes = array_values($keyed);
    sort($nodes);

    expect($nodes)->toBe(['A', 'B', 'C', 'D']);
})->with([
    'ids spelling the qualifiers' => [[
        ['X', 'S', 'F', 'A'],
        ['X', 'T', 'G', 'B'],
        ['X#S', 'U', 'H', 'C'],
        ['X#S#F', 'V', 'I', 'D'],
    ]],
    // And an artifact that writes the qualified form outright: whatever mark it spells, the qualified space
    // starts one `#` past the longest run any of these ids opens with.
    'an id spelling the qualified form' => [[
        ['X', 'S', 'F', 'A'],
        ['X', 'T', 'G', 'B'],
        ['#i1#1#X#1#S', 'U', 'H', 'C'],
        ['#i1#1#X#1#T', 'V', 'I', 'D'],
    ]],
]);

it('moves a node onto its counterpart\'s key only where one node on each side is left over', function (): void {
    // Stated in the keys, because the repair writes into the same key space the pairing already used: land
    // a re-paired node on a key another node holds and that node leaves the comparison entirely.
    [$old, $new] = IdentityKeys::pairLeftoversByStructure(
        [['X', 'name:A', 'F', 'old A'], ['Y', 'name:B', 'G', 'old B']],
        [['Z', 'name:A', 'H', 'new A'], ['Y', 'name:C', 'I', 'renamed B']],
    );

    expect($old)->toBe(['Z' => 'old A', 'Y' => 'old B'])
        ->and($new)->toBe(['Z' => 'new A', 'Y' => 'renamed B']);
});

it('leaves a structural key two left-over nodes claim alone', function (): void {
    // Two nodes on one side claiming one structural key name no single node to pair with, and moving both
    // onto the one counterpart's key would hide whichever moved first.
    [$old, $new] = IdentityKeys::pairLeftoversByStructure(
        [['X', 'name:A', 'F', 'old A'], ['W', 'name:A', 'G', 'old A twice']],
        [['Z', 'name:A', 'H', 'new A']],
    );

    $nodes = [...array_values($old), ...array_values($new)];
    sort($nodes);

    expect($nodes)->toBe(['new A', 'old A', 'old A twice'])
        ->and(array_intersect(array_keys($old), array_keys($new)))->toBe([]);
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

// --- Webhooks ---------------------------------------------------------------

/**
 * `diffBase()` plus a webhook: an operation the API promises to CALL. It is published in the same
 * document, and a consumer writes an endpoint against it exactly as it writes a request against a path.
 *
 * @return array<string, mixed>
 */
function diffWebhook(string $name = 'formSaved'): array
{
    $doc = diffBase();
    $doc['webhooks'][$name] = [
        'post' => [
            'x-docuccino' => ['id' => 'op:v1:7777777777777777'],
            'operationId' => 'forms.saved',
            'summary' => 'A form was saved',
            'parameters' => [[
                'x-docuccino' => ['id' => 'par:v1:5555555555555555'],
                'name' => 'X-Signature', 'in' => 'header', 'required' => false,
                'schema' => ['type' => 'string'],
            ]],
            'responses' => [
                '200' => [
                    'x-docuccino' => ['id' => 'res:v1:6666666666666666'],
                    'description' => 'Ack',
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']],
                    ]]],
                ],
            ],
        ],
    ];

    return $doc;
}

it('reports a webhook the new document no longer publishes', function (): void {
    // Walking `paths` alone left every webhook uncompared, so dropping one — the provider stopping a call
    // its consumers built an endpoint for — read as no change at all.
    $changes = changesByCode(diffOf(diffWebhook(), diffBase()));

    expect(diffOf(diffWebhook(), diffWebhook())->isEmpty())->toBeTrue()
        ->and($changes)->toHaveKey('operation.removed')
        ->and($changes['operation.removed']->breaking)->toBeTrue()
        ->and($changes['operation.removed']->path)->toBe('POST webhooks.formSaved');
});

it('diffs a webhook with the machinery an operation under paths gets', function (callable $edit, string $code, bool $breaking): void {
    $new = $edit(diffWebhook());
    $changes = changesByCode(diffOf(diffWebhook(), $new));

    expect($changes)->toHaveKey($code)
        ->and($changes[$code]->breaking)->toBe($breaking)
        ->and($changes[$code]->path)->toStartWith('POST webhooks.formSaved');
})->with([
    'a parameter becoming required' => [function (array $doc): array {
        $doc['webhooks']['formSaved']['post']['parameters'][0]['required'] = true;

        return $doc;
    }, 'parameter.became-required', true],
    'a response dropped' => [function (array $doc): array {
        unset($doc['webhooks']['formSaved']['post']['responses']['200']);

        return $doc;
    }, 'response.removed', true],
    'a property gone from the body it sends' => [function (array $doc): array {
        unset($doc['webhooks']['formSaved']['post']['responses']['200']['content']['application/json']['schema']['properties']['ok']);

        return $doc;
    }, 'schema.property-removed', true],
    'a summary rewritten' => [function (array $doc): array {
        $doc['webhooks']['formSaved']['post']['summary'] = 'A form was stored';

        return $doc;
    }, 'operation.summary-changed', false],
]);

it('keeps a webhook and a path template of the same name apart', function (): void {
    // Nothing stops a webhook being named like a path, and with neither side carrying identities the name
    // is all there is to key on: keyed on it alone the two are one operation, and an edit to the webhook
    // reads as an edit to the path — or, once their content parts them, as both being replaced.
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'paths' => ['/forms' => ['get' => ['summary' => 'List forms', 'responses' => ['200' => ['description' => 'Forms']]]]],
        'webhooks' => ['/forms' => ['get' => ['summary' => 'Poll me', 'responses' => ['200' => ['description' => 'Ack']]]]],
    ];

    $new = $doc;
    $new['webhooks']['/forms']['get']['summary'] = 'Poll me twice';

    $changeset = diffOf($doc, $new);
    $summaries = array_values(array_filter($changeset->changes, static fn (Change $c): bool => $c->code === 'operation.summary-changed'));

    expect(diffOf($doc, $doc)->isEmpty())->toBeTrue()
        ->and($summaries)->toHaveCount(1)
        ->and($summaries[0]->path)->toBe('GET webhooks./forms');
});

it('counts a webhook id when judging overlap', function (): void {
    // The warning that a kind paired nothing reads identity where the pairing reads it, and the pairing
    // now reads webhooks — otherwise a document whose operations are mostly webhooks goes quiet on exactly
    // the pairing failure the warning exists to flag.
    $old = diffWebhook();
    $old['webhooks']['formDeleted'] = $old['webhooks']['formSaved'];
    $old['webhooks']['formDeleted']['post']['x-docuccino']['id'] = 'op:v1:8888888888888888';
    unset($old['paths']);

    $new = $old;
    $new['webhooks']['formSaved']['post']['x-docuccino']['id'] = 'op:v1:2222222222222222';
    $new['webhooks']['formDeleted']['post']['x-docuccino']['id'] = 'op:v1:3333333333333333';

    expect(diffOf($old, $new)->disjointIdentities)->toBe(['operation']);
});

// --- Security schemes -------------------------------------------------------

/**
 * `diffBase()` with a `components.securitySchemes` entry, and by default an operation requiring it — a
 * document saying what a client must send to be let in at all.
 *
 * @param  array<string, mixed>  $scheme
 * @return array<string, mixed>
 */
function diffSecured(array $scheme = ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'], bool $required = true): array
{
    $doc = diffBase();
    $doc['components']['securitySchemes'] = ['apiKey' => $scheme];

    if ($required) {
        $doc['paths']['/api/v1/forms/{id}']['get']['security'] = [['apiKey' => []]];
    }

    return $doc;
}

it('reads a security scheme as the scheme it points at', function (): void {
    // `components.securitySchemes` takes a Reference Object too, and a scheme is compared member by member —
    // so read as written, a pointer IS the contract: hoisting one reported `$ref`, `type`, `in` and `name` as
    // the way in changing, which is breaking, on a contract that had not moved.
    $scheme = ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'];

    $inline = diffSecured($scheme);
    $inline['components']['securitySchemes']['shared'] = $scheme;

    $hoisted = diffSecured(['$ref' => '#/components/securitySchemes/shared']);
    $hoisted['components']['securitySchemes']['shared'] = $scheme;

    expect(diffOf($inline, $hoisted)->isEmpty())->toBeTrue()
        ->and(diffOf($hoisted, $inline)->isEmpty())->toBeTrue();

    // And an edit to the shared scheme still breaks the name that points at it, which is the name a
    // requirement asks for.
    $changed = $hoisted;
    $changed['components']['securitySchemes']['shared']['in'] = 'query';
    $breaking = diffOf($hoisted, $changed)->breakingChanges();

    expect($breaking)->toHaveCount(1)
        ->and($breaking[0]->code)->toBe('securityScheme.changed')
        ->and($breaking[0]->path)->toBe('components.securitySchemes.apiKey');
});

it('reports a scheme that changed how a client authenticates as breaking', function (): void {
    // Never compared at all, this said nothing: the key a client had been sending in a header now has to
    // go in the query string under another name, and the report was "No API changes."
    $changeset = diffOf(diffSecured(), diffSecured(['type' => 'apiKey', 'in' => 'query', 'name' => 'token']));
    $changes = changesByCode($changeset);

    expect($changes)->toHaveKey('securityScheme.changed')
        ->and($changes['securityScheme.changed']->breaking)->toBeTrue()
        ->and($changes['securityScheme.changed']->path)->toBe('components.securitySchemes.apiKey')
        ->and($changes['securityScheme.changed']->target->value)->toBe('securityScheme')
        ->and(array_map(static fn (array $f): array => $f, $changes['securityScheme.changed']->toArray()['fields']))->toBe([
            ['field' => 'in', 'old' => 'header', 'new' => 'query'],
            ['field' => 'name', 'old' => 'X-Api-Key', 'new' => 'token'],
        ])
        ->and($changeset->unreferencedComponents)->toBe([]);
});

it('reads every member that says how to satisfy a scheme as contract', function (array $old, array $new, string $member): void {
    // The member list is OAS's, not ours, and the ones we cannot name are the ones a later OAS adds — so
    // anything that is not prose counts, and a scheme's own `type` decides which members it even has.
    $changes = changesByCode(diffOf(diffSecured($old), diffSecured($new)));

    expect($changes)->toHaveKey('securityScheme.changed')
        ->and($changes['securityScheme.changed']->breaking)->toBeTrue()
        ->and(array_map(static fn (FieldChange $f): string => $f->field, $changes['securityScheme.changed']->fields))->toBe([$member]);
})->with([
    'type' => [['type' => 'http', 'scheme' => 'bearer'], ['type' => 'mutualTLS', 'scheme' => 'bearer'], 'type'],
    'in' => [['type' => 'apiKey', 'in' => 'header', 'name' => 'K'], ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'K'], 'in'],
    'name' => [['type' => 'apiKey', 'in' => 'header', 'name' => 'K'], ['type' => 'apiKey', 'in' => 'header', 'name' => 'J'], 'name'],
    'scheme' => [['type' => 'http', 'scheme' => 'bearer'], ['type' => 'http', 'scheme' => 'basic'], 'scheme'],
    'bearerFormat' => [['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'opaque'], 'bearerFormat'],
    'flows' => [
        ['type' => 'oauth2', 'flows' => ['implicit' => ['authorizationUrl' => 'https://id.test/a', 'scopes' => ['forms:read' => 'Read forms']]]],
        ['type' => 'oauth2', 'flows' => ['implicit' => ['authorizationUrl' => 'https://id.test/a', 'scopes' => []]]],
        'flows',
    ],
    'openIdConnectUrl' => [
        ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://id.test/a'],
        ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://id.test/b'],
        'openIdConnectUrl',
    ],
    'a member a later OAS adds' => [
        ['type' => 'oauth2', 'oauth2MetadataUrl' => 'https://id.test/a'],
        ['type' => 'oauth2', 'oauth2MetadataUrl' => 'https://id.test/b'],
        'oauth2MetadataUrl',
    ],
]);

it('never breaks over what a scheme says about itself', function (array $new, ?string $code): void {
    // Prose and a deprecation announce a change rather than make one, and an extension is a tool's own
    // business: none of them is a reason to fail a CI gate.
    $changeset = diffOf(diffSecured(['type' => 'apiKey', 'in' => 'header', 'name' => 'K']), diffSecured($new));

    expect($changeset->isBreaking())->toBeFalse()
        ->and(array_keys(changesByCode($changeset)))->toBe($code === null ? [] : [$code]);
})->with([
    'a description' => [['type' => 'apiKey', 'in' => 'header', 'name' => 'K', 'description' => 'Ask support.'], 'securityScheme.description-changed'],
    'a summary' => [['type' => 'apiKey', 'in' => 'header', 'name' => 'K', 'summary' => 'The key'], 'securityScheme.summary-changed'],
    'a deprecation' => [['type' => 'apiKey', 'in' => 'header', 'name' => 'K', 'deprecated' => true], 'securityScheme.deprecated-changed'],
    'an extension' => [['type' => 'apiKey', 'in' => 'header', 'name' => 'K', 'x-vault' => 'kv/api'], null],
]);

it('reports a scheme removed while a requirement still names it as breaking', function (): void {
    // The operation still says "authenticate with apiKey" and the document no longer says how. A client
    // generated from it cannot be built, let alone authenticate.
    $new = diffSecured();
    unset($new['components']['securitySchemes']['apiKey']);

    $changes = changesByCode(diffOf(diffSecured(), $new));

    expect($changes)->toHaveKey('securityScheme.removed')
        ->and($changes['securityScheme.removed']->breaking)->toBeTrue()
        ->and($changes['securityScheme.removed']->path)->toBe('components.securitySchemes.apiKey');
});

it('leaves a scheme dropped along with the requirements naming it non-breaking', function (): void {
    // An API that stopped asking for a key breaks nobody: every old client still authenticates, and its
    // credentials are simply ignored.
    $new = diffBase();

    $changes = changesByCode(diffOf(diffSecured(), $new));

    expect($changes)->toHaveKey('securityScheme.removed')
        ->and($changes['securityScheme.removed']->breaking)->toBeFalse()
        ->and($changes['operation.security-removed']->breaking)->toBeFalse();
});

it('classifies an added scheme as non-breaking', function (): void {
    $changes = changesByCode(diffOf(diffBase(), diffSecured(required: false)));

    expect($changes)->toHaveKey('securityScheme.added')
        ->and($changes['securityScheme.added']->breaking)->toBeFalse();
});

it('never breaks over a scheme no requirement names, and says so', function (): void {
    // The security half of the unreachable-schema rule: a hand-written artifact routinely carries a shelf
    // of schemes nothing asks for, and nothing has to satisfy one of those.
    $changeset = diffOf(
        diffSecured(required: false),
        diffSecured(['type' => 'apiKey', 'in' => 'query', 'name' => 'token'], required: false),
    );
    $rendered = (new ChangesetRenderer)->render($changeset);

    expect(changesByCode($changeset))->toHaveKey('securityScheme.changed')
        ->and($changeset->isBreaking())->toBeFalse()
        ->and($changeset->unreferencedComponents)->toBe(['components.securitySchemes.apiKey'])
        ->and($rendered)->toContain('nothing in either document references components.securitySchemes.apiKey')
        ->and($rendered)->toContain('never breaking');
});

it('finds a requirement wherever an artifact states it', function (callable $require): void {
    // Where a requirement can be written and is not looked for, a scheme reads as one nothing asks for and
    // a real break is stood down to a report line.
    $old = $require(diffSecured(required: false));
    $new = $require(diffSecured(['type' => 'apiKey', 'in' => 'query', 'name' => 'token'], required: false));

    $changeset = diffOf($old, $new);

    expect($changeset->isBreaking())->toBeTrue()
        ->and($changeset->unreferencedComponents)->toBe([]);
})->with([
    'on the document' => [function (array $doc): array {
        $doc['security'] = [['apiKey' => []]];

        return $doc;
    }],
    'on an operation' => [function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}']['get']['security'] = [['apiKey' => []]];

        return $doc;
    }],
    'on a webhook' => [function (array $doc): array {
        $doc['webhooks']['formSaved'] = ['post' => [
            'security' => [['apiKey' => []]],
            'responses' => ['200' => ['description' => 'Ack']],
        ]];

        return $doc;
    }],
    'on a callback the operation declares' => [function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}']['get']['callbacks'] = ['onSaved' => ['{$request.body#/url}' => ['post' => [
            'security' => [['apiKey' => []]],
            'responses' => ['200' => ['description' => 'Ack']],
        ]]]];

        return $doc;
    }],
    'on a path item under components' => [function (array $doc): array {
        $doc['components']['pathItems'] = ['Saved' => ['post' => [
            'security' => [['apiKey' => []]],
            'responses' => ['200' => ['description' => 'Ack']],
        ]]];

        return $doc;
    }],
    // OAS says `security` IS a list, so each of these is malformed — and states one requirement all the
    // same. Read as the list they are not, the scheme name is gone before anything asks who requires it.
    'as a bare map on the document' => [function (array $doc): array {
        $doc['security'] = ['apiKey' => []];

        return $doc;
    }],
    'as a bare map on an operation' => [function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}']['get']['security'] = ['apiKey' => []];

        return $doc;
    }],
    'as a bare map on a path item under components' => [function (array $doc): array {
        $doc['components']['pathItems'] = ['Saved' => ['post' => [
            'security' => ['apiKey' => []],
            'responses' => ['200' => ['description' => 'Ack']],
        ]]];

        return $doc;
    }],
]);

it('reads no scheme out of a requirement that names none', function (mixed $security, string $scheme): void {
    // The other half of the shape above: a `security` member an artifact wrote that names nothing readable.
    // Each row sits beside the scheme a reader that took the shape at face value would have named — an
    // empty key, or a requirement's VALUES — so over-collecting stands this cosmetic edit up as a break.
    $secured = static function (array $definition) use ($security, $scheme): array {
        $doc = diffBase();
        $doc['components']['securitySchemes'] = [$scheme => $definition];
        // A component path item, so the malformed member reaches the reader exactly as written — an
        // operation's own `security` is modelled as a list and would tidy half of these away first.
        $doc['components']['pathItems'] = ['Saved' => ['post' => [
            'security' => $security,
            'responses' => ['200' => ['description' => 'Ack']],
        ]]];

        return $doc;
    };

    $changeset = diffOf(
        $secured(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']),
        $secured(['type' => 'apiKey', 'in' => 'query', 'name' => 'token']),
    );

    expect(changesByCode($changeset))->toHaveKey('securityScheme.changed')
        ->and($changeset->isBreaking())->toBeFalse()
        ->and($changeset->unreferencedComponents)->toBe(['components.securitySchemes.'.$scheme]);
})->with([
    'an empty name where the map is written bare' => [['' => []], ''],
    'an empty name inside a requirement' => [[['' => []]], ''],
    'a requirement that is not a map at all' => [['see the wiki'], 'apiKey'],
    'a requirement written as a list of names' => [[['apiKey', 'oauth2']], 'apiKey'],
]);

it('survives a securitySchemes section that is not what it claims', function (mixed $section): void {
    // Everything here is read off an artifact nobody validated. A section that is a string, or a scheme
    // that is, describes no way in — and must not be a crash or a type error on the way to saying so.
    $doc = diffBase();
    $doc['components']['securitySchemes'] = $section;

    expect(diffOf($doc, $doc)->isEmpty())->toBeTrue()
        ->and(diffOf($doc, diffBase())->isBreaking())->toBeFalse();
})->with([
    'a string' => ['#/components/securitySchemes'],
    'a list' => [[['type' => 'apiKey']]],
    'a scheme that is a string' => [['apiKey' => 'see the wiki']],
    'a scheme that is null' => [['apiKey' => null]],
]);

it('escapes a scheme member name it reads straight off the artifact', function (): void {
    // Every other field name in a changeset is a literal of ours; a scheme's members are whatever keys the
    // artifact wrote. Left raw, one of them erases and rewrites the line that just called it BREAKING.
    $member = "scheme\x1B[2K\rNON-BREAKING: nothing to review\n";

    $changeset = diffOf(
        diffSecured(['type' => 'http', 'scheme' => 'bearer', $member => 'before']),
        diffSecured(['type' => 'http', 'scheme' => 'bearer', $member => 'after']),
    );
    $rendered = (new ChangesetRenderer)->render($changeset);

    expect($changeset->isBreaking())->toBeTrue()
        ->and($rendered)->not->toContain("\x1B")
        ->and($rendered)->not->toContain("\r")
        ->and($rendered)->toContain('scheme\x1B[2K\x0DNON-BREAKING: nothing to review\x0A: before -> after')
        // The forged verdict is text inside the one field line, not a line of its own.
        ->and(substr_count($rendered, "\n"))->toBe(5);
});

it('invents no churn from the order a document lists its schemes in', function (): void {
    $old = diffSecured();
    $old['components']['securitySchemes']['oauth2'] = ['type' => 'oauth2', 'flows' => []];

    $new = $old;
    $new['components']['securitySchemes'] = array_reverse($old['components']['securitySchemes'], preserve_keys: true);

    expect(diffOf($old, $new)->isEmpty())->toBeTrue();
});

// --- Schemas nothing references ---------------------------------------------

/**
 * `diffBase()`'s component schema, referenced from the 200 response. `diffBase()` itself declares it and
 * points at nothing, which is the unreachable half of every pair below: one mutation, two documents, and
 * the only difference is whether an operation can reach the schema it edits.
 *
 * @return array<string, mixed>
 */
function diffReferencedSchema(): array
{
    $doc = diffBase();
    $doc['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema'] = [
        '$ref' => '#/components/schemas/FormData',
    ];

    return $doc;
}

/**
 * The edit under test: the schema loses a property and changes its type — breaking wherever a consumer
 * can reach it.
 *
 * @param  array<string, mixed>  $doc
 * @return array<string, mixed>
 */
function diffShrunkSchema(array $doc, string $name = 'FormData'): array
{
    unset($doc['components']['schemas'][$name]['properties']['id']);
    $doc['components']['schemas'][$name]['type'] = 'string';

    return $doc;
}

it('reports a change to a schema nothing references, but never as breaking', function (): void {
    // `docuccino:diff <file>` reads an artifact another tool wrote, and an unreferenced `components.schemas`
    // entry is ordinary there. A schema no operation reaches is in no request and no response, so no edit to
    // it can break a consumer — calling one breaking fails a CI gate over a contract that did not move.
    $changeset = diffOf(diffBase(), diffShrunkSchema(diffBase()));
    $changes = changesByCode($changeset);

    expect($changes)->toHaveKey('schema.property-removed')
        ->and($changes)->toHaveKey('schema.type-changed')
        ->and($changeset->isBreaking())->toBeFalse()
        ->and($changeset->unreferencedComponents)->toBe(['components.schemas.FormData'])
        ->and($changeset->toArray()['unreferencedComponents'])->toBe(['components.schemas.FormData']);
});

it('stands the same change down where the schema was re-minted along with the edit', function (): void {
    // The edit reaches the comparison however the artifact minted the id — and a schema nothing reaches is
    // still in no request and no response, so the stand-down is the same one.
    $new = diffShrunkSchema(diffBase());
    $new['components']['schemas']['FormData']['x-docuccino']['id'] = 'sch:v1:5555555555555555';

    $changeset = diffOf(diffBase(), $new);
    $changes = changesByCode($changeset);

    expect($changes)->toHaveKey('schema.property-removed')
        ->and($changes['schema.property-removed']->breaking)->toBeFalse()
        ->and($changeset->isBreaking())->toBeFalse()
        ->and($changeset->unreferencedComponents)->toBe(['components.schemas.FormData']);
});

it('keeps the very same change breaking once an operation references that schema', function (): void {
    $changeset = diffOf(diffReferencedSchema(), diffShrunkSchema(diffReferencedSchema()));
    $changes = changesByCode($changeset);

    expect($changes['schema.property-removed']->breaking)->toBeTrue()
        ->and($changes['schema.type-changed']->breaking)->toBeTrue()
        ->and($changeset->unreferencedComponents)->toBe([]);
});

it('finds the reference wherever an artifact put it', function (callable $reference): void {
    // Reachability decides whether a breaking rule applies, so a place it cannot look is a place a real
    // break is quietly downgraded. Every one of these is a shape a hand-written artifact reaches for.
    $old = $reference(diffBase());
    $changeset = diffOf($old, diffShrunkSchema($old));

    expect($changeset->isBreaking())->toBeTrue()
        ->and($changeset->unreferencedComponents)->toBe([]);
})->with([
    'a response schema' => [fn (array $doc): array => diffReferencedSchema()],
    'a response schema under items' => [function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema'] = [
            'type' => 'array', 'items' => ['$ref' => '#/components/schemas/FormData'],
        ];

        return $doc;
    }],
    'a pointer into the schema' => [function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema'] = [
            'properties' => ['id' => ['$ref' => '#/components/schemas/FormData/properties/id']],
        ];

        return $doc;
    }],
    'a request body' => [function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}']['get']['requestBody'] = [
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FormData']]],
        ];

        return $doc;
    }],
    'a parameter schema' => [function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['$ref' => '#/components/schemas/FormData'];

        return $doc;
    }],
    'a response header' => [function (array $doc): array {
        $doc['paths']['/api/v1/forms/{id}']['get']['responses']['200']['headers'] = [
            'X-Form' => ['schema' => ['$ref' => '#/components/schemas/FormData']],
        ];

        return $doc;
    }],
    'a hoisted response' => [function (array $doc): array {
        $doc['components']['responses']['NotFound'] = [
            'description' => 'Not found',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FormData']]],
        ];
        $doc['paths']['/api/v1/forms/{id}']['get']['responses']['404'] = ['$ref' => '#/components/responses/NotFound'];

        return $doc;
    }],
    'a hoisted parameter' => [function (array $doc): array {
        $doc['components']['parameters']['Filter'] = [
            'name' => 'filter', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/FormData'],
        ];
        $doc['paths']['/api/v1/forms/{id}']['get']['parameters'][] = ['$ref' => '#/components/parameters/Filter'];

        return $doc;
    }],
    'a webhook' => [function (array $doc): array {
        $doc['webhooks']['formSaved'] = ['post' => ['responses' => [
            '200' => ['description' => 'Ack', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FormData']]]],
        ]]];

        return $doc;
    }],
    'a discriminator mapping' => [function (array $doc): array {
        $doc['components']['schemas']['Envelope'] = [
            'x-docuccino' => ['id' => 'sch:v1:1111111111111111'],
            'oneOf' => [['type' => 'object']],
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['form' => '#/components/schemas/FormData']],
        ];
        $doc['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema'] = [
            '$ref' => '#/components/schemas/Envelope',
        ];

        return $doc;
    }],
    'another schema that is itself referenced' => [function (array $doc): array {
        $doc['components']['schemas']['FormEnvelope'] = [
            'x-docuccino' => ['id' => 'sch:v1:1111111111111111'],
            'type' => 'object',
            'properties' => ['form' => ['$ref' => '#/components/schemas/FormData']],
        ];
        $doc['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema'] = [
            '$ref' => '#/components/schemas/FormEnvelope',
        ];

        return $doc;
    }],
]);

it('reads a schema name a pointer had to escape', function (string $name, string $ref): void {
    // A component name is free-form, so the pointer that names it escapes `/` and `~` the JSON-Pointer way
    // and the rest the URI way. Read literally, an escaped name matches nothing and the schema it names
    // looks unused.
    $old = diffBase();
    $old['components']['schemas'][$name] = $old['components']['schemas']['FormData'];
    unset($old['components']['schemas']['FormData']);
    $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema'] = ['$ref' => $ref];

    expect(diffOf($old, diffShrunkSchema($old, $name))->isBreaking())->toBeTrue();
})->with([
    'a slash' => ['Form/Data', '#/components/schemas/Form~1Data'],
    'a tilde' => ['Form~Data', '#/components/schemas/Form~0Data'],
    'a space' => ['Form Data', '#/components/schemas/Form%20Data'],
]);

it('reads every schema one string names, not only the first', function (): void {
    // The scan resumes INSIDE the pointer it just read rather than past it, so a second pointer in the same
    // string is still found. A description citing two components is the shape that needs it, and stopping at
    // the first would leave the second schema looking unused — standing a real break down to a report line.
    $old = diffBase();
    $old['components']['schemas']['FormNote'] = [
        'x-docuccino' => ['id' => 'sch:v1:1111111111111111'],
        'type' => 'object',
        'properties' => ['note' => ['type' => 'string']],
    ];
    $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']['description'] =
        'Either #/components/schemas/FormNote/properties/note or #/components/schemas/FormData';

    $changeset = diffOf($old, diffShrunkSchema($old));

    expect($changeset->isBreaking())->toBeTrue()
        ->and($changeset->unreferencedComponents)->toBe([]);
});

it('survives a pointer that names nothing', function (): void {
    // An artifact nobody validated can point at a component it never declares, or at no component at all.
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema'] = [
        'oneOf' => [['$ref' => '#/components/schemas/Missing'], ['$ref' => '#/components/schemas/']],
    ];

    $changeset = diffOf($old, diffShrunkSchema($old));

    expect($changeset->isBreaking())->toBeFalse()
        ->and($changeset->unreferencedComponents)->toBe(['components.schemas.FormData']);
});

it('does not let one unreachable schema vouch for another', function (): void {
    // Reachability is transitive from the operations, not "something points at it": a pointer held by a
    // schema nobody reaches reaches nothing itself.
    $old = diffBase();
    $old['components']['schemas']['FormHolder'] = [
        'x-docuccino' => ['id' => 'sch:v1:1111111111111111'],
        'type' => 'object',
        'properties' => ['form' => ['$ref' => '#/components/schemas/FormData']],
    ];

    $changeset = diffOf($old, diffShrunkSchema($old));

    expect($changeset->isBreaking())->toBeFalse()
        ->and($changeset->unreferencedComponents)->toBe(['components.schemas.FormData']);
});

it('terminates on a cycle between the schemas it does reach', function (): void {
    // Nothing stops an artifact pointing two schemas at each other, and a recursive type is the ordinary
    // reason to. Walked without a visited set this never returns — a hang, not a wrong answer.
    $old = diffReferencedSchema();
    $old['components']['schemas']['FormData']['properties']['loop'] = ['$ref' => '#/components/schemas/FormLoop'];
    $old['components']['schemas']['FormLoop'] = [
        'x-docuccino' => ['id' => 'sch:v1:1111111111111111'],
        'type' => 'object',
        'properties' => ['back' => ['$ref' => '#/components/schemas/FormData']],
    ];

    $new = $old;
    unset($new['components']['schemas']['FormLoop']['properties']['back']);

    $changeset = diffOf($old, $new);

    expect($changeset->isBreaking())->toBeTrue()
        ->and($changeset->unreferencedComponents)->toBe([]);
});

it('keeps a cycle between unreachable schemas unreachable', function (): void {
    $old = diffBase();
    $old['components']['schemas']['FormData']['properties']['loop'] = ['$ref' => '#/components/schemas/FormLoop'];
    $old['components']['schemas']['FormLoop'] = [
        'x-docuccino' => ['id' => 'sch:v1:1111111111111111'],
        'type' => 'object',
        'properties' => ['back' => ['$ref' => '#/components/schemas/FormData']],
    ];

    $new = $old;
    unset($new['components']['schemas']['FormLoop']['properties']['back']);

    $changeset = diffOf($old, $new);

    expect($changeset->isBreaking())->toBeFalse()
        ->and($changeset->unreferencedComponents)->toBe(['components.schemas.FormLoop']);
});

it('stands nothing down for a schema either side still reaches', function (): void {
    // Reachable on ONE side is enough: the old document's consumers read it, so what changed under them is
    // a break whether or not the new document still points at it.
    $shrunk = diffShrunkSchema(diffBase());

    expect(diffOf(diffReferencedSchema(), $shrunk)->isBreaking())->toBeTrue()
        ->and(diffOf(diffBase(), diffShrunkSchema(diffReferencedSchema()))->isBreaking())->toBeTrue();
});

it('calls deleting a schema a live pointer still names breaking', function (): void {
    // The mirror of the rule above: the reference stays, the schema goes, and the pointer names nothing.
    // The document is invalid AND every client generated from it loses the type that pointer stood for —
    // reported, as it was, as an ordinary non-breaking `schema.removed`, that passes `--enforce`.
    $new = diffReferencedSchema();
    unset($new['components']['schemas']['FormData']);

    $changes = changesByCode(diffOf(diffReferencedSchema(), $new));

    expect($changes)->toHaveKey('schema.removed-still-referenced')
        ->and($changes['schema.removed-still-referenced']->breaking)->toBeTrue()
        ->and($changes['schema.removed-still-referenced']->path)->toBe('components.schemas.FormData')
        ->and($changes)->not->toHaveKey('schema.removed');
});

it('leaves a schema deleted along with the pointers at it non-breaking', function (): void {
    // Nothing dangles, so nothing broke: the response that used to point at the schema states its own body
    // now, and that body is compared where it lives.
    $new = diffBase();
    unset($new['components']['schemas']['FormData']);

    $changes = changesByCode(diffOf(diffReferencedSchema(), $new));

    expect($changes)->toHaveKey('schema.removed')
        ->and($changes['schema.removed']->breaking)->toBeFalse()
        ->and($changes)->not->toHaveKey('schema.removed-still-referenced');
});

it('reads a pointer held only by a schema nothing reaches as no reference at all', function (): void {
    // Reachability is transitive, here as everywhere: a pointer inside an unreachable schema is one no
    // consumer can follow, so the removal it survives breaks nobody.
    $old = diffBase();
    $old['components']['schemas']['FormHolder'] = [
        'x-docuccino' => ['id' => 'sch:v1:1111111111111111'],
        'type' => 'object',
        'properties' => ['form' => ['$ref' => '#/components/schemas/FormData']],
    ];

    $new = $old;
    unset($new['components']['schemas']['FormData']);

    $changes = changesByCode(diffOf($old, $new));

    expect($changes)->toHaveKey('schema.removed')
        ->and($changes['schema.removed']->breaking)->toBeFalse();
});

it('reads a schema re-minted under its own name as the same schema', function (): void {
    // Nothing about the published contract moved: the name resolves to a body stating exactly what it
    // stated before. Read as a removal and an addition it would be neither compared nor, once the removal
    // met the dangling check, reliably harmless.
    $old = diffReferencedSchema();

    $new = $old;
    $new['components']['schemas']['FormData']['x-docuccino']['id'] = 'sch:v1:1111111111111111';

    expect(diffOf($old, $new)->isEmpty())->toBeTrue();
});

it('says in the rendered report which schema it stood down', function (): void {
    // A breaking change silently reclassified is the one thing a differ must not do quietly: a reader who
    // knows the schema IS used needs to see which verdict to distrust.
    $rendered = (new ChangesetRenderer)->render(diffOf(diffBase(), diffShrunkSchema(diffBase())));

    expect($rendered)->toContain('nothing in either document references components.schemas.FormData')
        ->and($rendered)->toContain('never breaking')
        ->and($rendered)->toContain('(0 breaking)');

    // …and stays quiet where the schema is reachable, so the note keeps meaning something.
    expect((new ChangesetRenderer)->render(diffOf(diffReferencedSchema(), diffShrunkSchema(diffReferencedSchema()))))
        ->not->toContain('nothing in either document references');
});

it('escapes a schema name it names in that note', function (): void {
    $old = diffBase();
    $old['components']['schemas']["Form\x1B[31mData"] = $old['components']['schemas']['FormData'];
    unset($old['components']['schemas']['FormData']);

    $rendered = (new ChangesetRenderer)->render(diffOf($old, diffShrunkSchema($old, "Form\x1B[31mData")));

    expect($rendered)->not->toContain("\x1B")
        ->and($rendered)->toContain('nothing in either document references components.schemas.Form\x1B[31mData');
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

/**
 * {@see diffBase()} with one keyword written onto the JSON response body's schema — one pointer, one
 * keyword, and nothing else in the document touched.
 *
 * @return array<string, mixed>
 */
function diffBaseWithSchemaKeyword(string $keyword, mixed $value): array
{
    $doc = diffBase();
    $doc['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema'][$keyword] = $value;

    return $doc;
}

/**
 * One edit, one keyword, both sides.
 */
function diffOfSchemaKeyword(string $keyword, mixed $before, mixed $after): Changeset
{
    return diffOf(diffBaseWithSchemaKeyword($keyword, $before), diffBaseWithSchemaKeyword($keyword, $after));
}

/** @return list<string> */
function diffCodes(Changeset $changeset): array
{
    return array_map(static fn (Change $change): string => $change->code, $changeset->changes);
}

/**
 * Every built-in versioning policy, each at a version its own grammar accepts and which did NOT move.
 * A policy that cannot read the versions violates before it ever looks at the changeset, so one pair of
 * strings cannot serve all three.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function unmovedVersionPerPolicy(): array
{
    return ['semver' => ['1.4.2', '1.4.2'], 'date' => ['2026-08-01', '2026-08-01'], 'none' => ['', '']];
}

/**
 * The annotation keywords, with an edit for each. The set itself is {@see SchemaKeywords}'s, and the
 * test below fails if this list is short of it.
 *
 * @return array<string, array{0: string, 1: mixed, 2: mixed}>
 */
function annotationKeywordEdits(): array
{
    return [
        '$comment' => ['$comment', 'written by the generator', 'written by hand'],
        'description' => ['description', 'The form', 'The form, as stored'],
        'example' => ['example', ['id' => 1], ['id' => 999]],
        'examples' => ['examples', [['id' => 1]], [['id' => 999]]],
        'externalDocs' => ['externalDocs', ['url' => 'https://forms.test/a'], ['url' => 'https://forms.test/b']],
        'title' => ['title', 'Form', 'Stored form'],
    ];
}

/**
 * Every keyword that lives in {@see SchemaKeywords}'s SUPERSESSION annotations and deliberately not in
 * its annotation-only set, with an edit for each and the codes that edit reports. The reasons are the
 * ones stated there; the test below holds this list to the difference between the two sets, so a new
 * annotation cannot land in neither.
 *
 * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: list<string>}>
 */
function contractBearingKeywordEdits(): array
{
    return [
        'default' => ['default', 'draft', 'published', []],
        'deprecated' => ['deprecated', false, true, []],
        'readOnly' => ['readOnly', false, true, []],
        'writeOnly' => ['writeOnly', false, true, []],
        // The two definition stores ARE compared, conditionally: a `$ref` can name any member, so a
        // member's polarity is whatever the refs naming it are worth ({@see SchemaPolarity}).
        '$defs' => ['$defs', ['Inner' => ['type' => 'string']], ['Inner' => ['type' => 'integer']], ['schema.type-changed']],
        'definitions' => ['definitions', ['Inner' => ['type' => 'string']], ['Inner' => ['type' => 'integer']], ['schema.type-changed']],
        // A name a `$ref` may resolve, and the dialect the keywords beside it are written in — read by
        // {@see SchemaReading}, which is where their reasons are stated.
        '$id' => ['$id', 'https://forms.test/schemas/a', 'https://forms.test/schemas/b', ['schema.identity-changed']],
        '$anchor' => ['$anchor', 'formA', 'formB', ['schema.identity-changed']],
        '$schema' => ['$schema', 'https://json-schema.org/draft/2020-12/schema', 'http://json-schema.org/draft-07/schema#', ['schema.dialect-changed']],
        'x-docuccino' => ['x-docuccino', ['id' => 'sch:v1:aaaaaaaaaaaaaaaa'], ['id' => 'sch:v1:bbbbbbbbbbbbbbbb'], []],
    ];
}

it('reports an annotation keyword as a non-breaking change that gates under no policy', function (string $keyword, mixed $before, mixed $after): void {
    $changeset = diffOfSchemaKeyword($keyword, $before, $after);

    expect(SchemaKeywords::isAnnotationOnly($keyword))->toBeTrue()
        ->and(diffCodes($changeset))->toBe(['schema.annotation-changed'])
        ->and($changeset->changes[0]->breaking)->toBeFalse()
        ->and($changeset->changes[0]->fields[0]->field)->toBe($keyword)
        ->and($changeset->changes[0]->path)->toEndWith('schema.'.$keyword)
        ->and($changeset->isBreaking())->toBeFalse();

    foreach (unmovedVersionPerPolicy() as $policy => [$oldVersion, $newVersion]) {
        expect(VersioningPolicies::for($policy)->evaluate($changeset, $oldVersion, $newVersion)->satisfied)
            ->toBeTrue("the {$policy} policy gated on an annotation-only changeset");
    }
})->with(annotationKeywordEdits());

it('never classifies a contract-bearing keyword as an annotation', function (string $keyword, mixed $before, mixed $after, array $codes): void {
    // What each edit reports is pinned rather than only "not an annotation", because that is the fact a
    // comparator arriving for one of them moves — and a comparator that classed one as an annotation
    // would be the mistake this row exists to catch. `[]` means the keyword is still compared by
    // nothing; whatever the row says, `schema.annotation-changed` is the one code it may never be.
    expect(SchemaKeywords::isAnnotationOnly($keyword))->toBeFalse()
        ->and(diffCodes(diffOfSchemaKeyword($keyword, $before, $after)))->toBe($codes)
        ->and($codes)->not->toContain('schema.annotation-changed');
})->with(contractBearingKeywordEdits());

/**
 * The two datasets above only prove the rows they list, and the split between them is the thing that
 * must not move quietly: a keyword wrongly called an annotation silences a real change, and a new
 * supersession annotation landing in NEITHER list is a decision nobody made. So both are derived from
 * the source of truth — the members from the annotation-only set, the exclusions from what the
 * supersession set has over it — and a keyword can only be added to one by choosing which.
 */
it('covers every annotation keyword there is, and every exclusion the supersession set has over it', function (): void {
    $listed = array_keys(annotationKeywordEdits());
    $actual = SchemaKeywords::annotationOnly();
    sort($listed);
    sort($actual);

    $excluded = array_keys(contractBearingKeywordEdits());
    $exclusions = array_values(array_diff(SchemaKeywords::annotations(), $actual));
    sort($excluded);
    sort($exclusions);

    expect($listed)->toBe($actual)
        ->and($excluded)->toBe($exclusions)
        // Anti-vacuity: two empty sets would agree with each other and prove nothing.
        ->and(count($actual))->toBeGreaterThanOrEqual(5)
        ->and(count($exclusions))->toBeGreaterThanOrEqual(8);

    foreach ($actual as $keyword) {
        expect(SchemaKeywords::classification()[$keyword] ?? null)->toBe('annotation', "{$keyword} is annotation-only but not an annotation");
    }

    // Every policy the version map names really is that policy: a typo'd row would resolve to the
    // no-versioning fallback and go on passing while proving nothing about the policy it names.
    foreach (array_keys(unmovedVersionPerPolicy()) as $policy) {
        expect(VersioningPolicies::for($policy)->name())->toBe($policy);
    }
});

it('keeps a schema change and an annotation change at one pointer apart', function (): void {
    $old = diffBaseWithSchemaKeyword('description', 'The form');
    $new = diffBaseWithSchemaKeyword('description', 'The form, as stored');
    // The same schema node, narrowed: a response body that was an object is now a string.
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['type'] = 'string';

    $changeset = diffOf($old, $new);
    $byCode = changesByCode($changeset);

    expect(diffCodes($changeset))->toContain('schema.annotation-changed', 'schema.type-changed')
        ->and($byCode['schema.type-changed']->breaking)->toBeTrue()
        ->and($byCode['schema.annotation-changed']->breaking)->toBeFalse()
        ->and($changeset->isBreaking())->toBeTrue()
        // Neither hides the other: each is in exactly one of the two buckets the renderer prints.
        ->and(array_map(static fn (Change $c): string => $c->code, $changeset->breakingChanges()))->toBe(['schema.type-changed'])
        ->and(array_map(static fn (Change $c): string => $c->code, $changeset->nonBreakingChanges()))->toBe(['schema.annotation-changed']);

    $rendered = (new ChangesetRenderer)->render($changeset);

    expect($rendered)->toContain('2 changes (1 breaking)')
        ->and($rendered)->toContain('schema.type-changed')
        ->and($rendered)->toContain('schema.annotation-changed');

    // The annotation change rescues nothing: every policy still gates on the narrowing beside it.
    foreach (unmovedVersionPerPolicy() as $policy => [$oldVersion, $newVersion]) {
        expect(VersioningPolicies::for($policy)->evaluate($changeset, $oldVersion, $newVersion)->satisfied)
            ->toBeFalse("the {$policy} policy passed a breaking change sharing a pointer with an annotation");
    }
});

it('renders an annotation-only changeset rather than reporting no API changes', function (): void {
    $changeset = diffOfSchemaKeyword('example', ['id' => 1], ['id' => 999]);
    $rendered = (new ChangesetRenderer)->render($changeset);

    expect($changeset->isEmpty())->toBeFalse()
        ->and($rendered)->not->toContain('No API changes.')
        ->and($rendered)->toContain('1 change (0 breaking)')
        ->and($rendered)->toContain('NON-BREAKING')
        ->and($rendered)->toContain('schema.annotation-changed')
        ->and($rendered)->toContain('example: {"id":1} -> {"id":999}');
});

it('tells an empty-object annotation from an empty list, and the same object from a change', function (): void {
    // Two `stdClass` standing for one JSON object are never the identical instance, so `===` read an
    // example that had not moved as one that had. `{}` and `[]` at the same keyword really do differ.
    expect(diffOf(diffBaseWithSchemaKeyword('example', new stdClass), diffBaseWithSchemaKeyword('example', new stdClass))->isEmpty())->toBeTrue()
        ->and(diffCodes(diffOfSchemaKeyword('example', new stdClass, [])))->toBe(['schema.annotation-changed']);
});

it('reports an annotation change inside a property, and on a named component', function (): void {
    $old = diffBase();
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['title']['description'] = 'The form title';
    $new['components']['schemas']['FormData']['title'] = 'Form data';

    $changeset = diffOf($old, $new);

    expect(diffCodes($changeset))->toBe(['schema.annotation-changed', 'schema.annotation-changed'])
        ->and($changeset->isBreaking())->toBeFalse()
        ->and(array_map(static fn (Change $c): string => $c->path, $changeset->changes))->toBe([
            'GET /api/v1/forms/{id} responses 200 application/json schema.properties.title.description',
            'components.schemas.FormData.title',
        ]);
});

/**
 * The fingerprint every value comparison here runs on, at the values JSON cannot spell. Its fallback
 * was `gettype()` — one key for every un-encodable value — so two of them in one enum compared EQUAL
 * and dropping one read as dropping nothing. Harmless where an annotation goes unreported; not where a
 * `schema.enum-value-removed` does, which is breaking.
 */
it('tells two values JSON cannot encode apart, so a removed enum value is still reported', function (mixed $kept, mixed $dropped): void {
    expect(json_encode([$kept, $dropped]))->toBeFalse();

    $changeset = diffOf(diffBaseWithSchemaKeyword('enum', [$kept, $dropped]), diffBaseWithSchemaKeyword('enum', [$kept]));

    expect(diffCodes($changeset))->toBe(['schema.enum-value-removed'])
        ->and($changeset->isBreaking())->toBeTrue()
        // And the value that stayed is still the same value: a fingerprint that told everything apart
        // would report it removed and re-added.
        ->and(diffOf(diffBaseWithSchemaKeyword('enum', [$kept]), diffBaseWithSchemaKeyword('enum', [$kept]))->isEmpty())->toBeTrue();
})->with([
    'strings that are not valid UTF-8' => ["\xB1\x31", "\xB2\x31"],
    'INF and NAN' => [INF, NAN],
]);
