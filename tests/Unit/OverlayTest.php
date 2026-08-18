<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Overlay\InvalidOverlayException;
use Docuccino\Core\Overlay\OverlayApplier;
use Docuccino\Core\Overlay\OverlayDocument;
use Docuccino\Core\SpecValidation\Validator;

/**
 * @return array<string, mixed>
 */
function overlayBaseDocument(): array
{
    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '1.0.0'],
        'paths' => [
            '/api/v1/forms' => [
                'get' => [
                    'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
                    'summary' => 'List forms',
                    'parameters' => [
                        ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];
}

/**
 * @param  list<array<string, mixed>>  $actions
 */
function overlayWith(array $actions): OverlayDocument
{
    return OverlayDocument::fromArray(['overlay' => '1.0.0', 'actions' => $actions]);
}

it('parses a well-formed overlay 1.0 document', function (): void {
    $overlay = OverlayDocument::fromArray([
        'overlay' => '1.0.0',
        'info' => ['title' => 'Tweaks', 'version' => '1'],
        'actions' => [
            ['target' => "\$.paths['/api/v1/forms'].get.description", 'update' => 'A better description'],
        ],
    ]);

    expect($overlay->overlay)->toBe('1.0.0');
    expect($overlay->actions)->toHaveCount(1);
    expect($overlay->actions[0]->hasUpdate)->toBeTrue();
});

it('rejects a missing or unsupported overlay version', function (): void {
    expect(fn () => OverlayDocument::fromArray(['actions' => []]))->toThrow(InvalidOverlayException::class);
    expect(fn () => OverlayDocument::fromArray(['overlay' => '2.0.0', 'actions' => []]))->toThrow(InvalidOverlayException::class);
});

it('rejects an action with neither update nor remove', function (): void {
    expect(fn () => overlayWith([['target' => '$.info.title']]))->toThrow(InvalidOverlayException::class);
});

it('updates a scalar member and records overlay provenance with the overridden value', function (): void {
    $overlay = overlayWith([
        ['target' => "\$.paths['/api/v1/forms'].get.summary", 'update' => 'List all forms'],
    ]);

    $result = (new OverlayApplier)->apply(overlayBaseDocument(), $overlay);
    $get = $result->document['paths']['/api/v1/forms']['get'];

    expect($get['summary'])->toBe('List all forms');

    $provenance = $get['x-docuccino']['provenance'];
    $overlayRecord = array_values(array_filter($provenance, static fn ($r) => $r['producer'] === 'overlay'))[0];
    expect($overlayRecord['layer'])->toBe('overlay');
    expect($overlayRecord['fields'])->toBe(['summary']);
    expect($overlayRecord['overrode'])->toBe([['field' => 'summary', 'value' => 'List forms']]);
    expect($result->diagnostics)->toBe([]);
});

it('deep-merges an object update, keeping inferred siblings', function (): void {
    $overlay = overlayWith([
        ['target' => "\$.paths['/api/v1/forms'].get", 'update' => ['description' => 'Added by overlay', 'summary' => 'Renamed']],
    ]);

    $result = (new OverlayApplier)->apply(overlayBaseDocument(), $overlay);
    $get = $result->document['paths']['/api/v1/forms']['get'];

    expect($get['description'])->toBe('Added by overlay');
    expect($get['summary'])->toBe('Renamed');
    // Inferred siblings survive the merge.
    expect($get['parameters'])->toHaveCount(2);
    expect($get['responses'])->toHaveKey('200');

    $overlayRecord = array_values(array_filter($get['x-docuccino']['provenance'], static fn ($r) => $r['producer'] === 'overlay'))[0];
    expect($overlayRecord['fields'])->toContain('description')->toContain('summary');
    // Only the pre-existing member (summary) was overridden.
    expect($overlayRecord['overrode'])->toBe([['field' => 'summary', 'value' => 'List forms']]);
});

it('removes a node targeted by an equality filter', function (): void {
    $overlay = overlayWith([
        ['target' => "\$.paths['/api/v1/forms'].get.parameters[?(@.name=='per_page')]", 'remove' => true],
    ]);

    $result = (new OverlayApplier)->apply(overlayBaseDocument(), $overlay);
    $parameters = $result->document['paths']['/api/v1/forms']['get']['parameters'];

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]['name'])->toBe('status');
    // The array was re-indexed to a clean list.
    expect(array_is_list($parameters))->toBeTrue();
});

it('updates a node targeted by an equality filter', function (): void {
    $overlay = overlayWith([
        ['target' => "\$.paths['/api/v1/forms'].get.parameters[?(@.name=='status')]", 'update' => ['description' => 'Filter by status']],
    ]);

    $result = (new OverlayApplier)->apply(overlayBaseDocument(), $overlay);
    $status = $result->document['paths']['/api/v1/forms']['get']['parameters'][0];

    expect($status['description'])->toBe('Filter by status');
    expect($status['name'])->toBe('status');
});

it('emits an error diagnostic for an unsupported selector, never a silent skip', function (): void {
    $overlay = overlayWith([
        ['target' => '$.paths..get', 'update' => ['description' => 'nope']],
    ]);

    $result = (new OverlayApplier)->apply(overlayBaseDocument(), $overlay);

    expect($result->document)->toBe(overlayBaseDocument());
    expect($result->hasErrors())->toBeTrue();
    expect($result->diagnostics[0]->severity)->toBe(Severity::Error);
    expect($result->diagnostics[0]->code)->toBe('overlay.unsupported-selector');
});

it('emits a warning diagnostic when a target matches nothing', function (): void {
    $overlay = overlayWith([
        ['target' => "\$.paths['/missing'].get.summary", 'update' => 'nope'],
    ]);

    $result = (new OverlayApplier)->apply(overlayBaseDocument(), $overlay);

    expect($result->document)->toBe(overlayBaseDocument());
    expect($result->diagnostics[0]->severity)->toBe(Severity::Warning);
    expect($result->diagnostics[0]->code)->toBe('overlay.target-missing');
});

it('produces a document that still validates against the UIR schema', function (): void {
    $overlay = overlayWith([
        ['target' => "\$.paths['/api/v1/forms'].get.summary", 'update' => 'List every form'],
    ]);

    $result = (new OverlayApplier)->apply(workedExample(), $overlay);

    $validation = (new Validator)->validate($result->document);

    expect($validation->isValid())->toBeTrue()
        ->and($validation->errors)->toBe([]);
});

it('emits an error diagnostic for an action declaring both update and remove', function (): void {
    $overlay = overlayWith([
        ['target' => "\$.paths['/api/v1/forms'].get.summary", 'update' => 'X', 'remove' => true],
    ]);

    $result = (new OverlayApplier)->apply(overlayBaseDocument(), $overlay);

    expect($result->document)->toBe(overlayBaseDocument());
    expect($result->hasErrors())->toBeTrue();
    expect($result->diagnostics[0]->severity)->toBe(Severity::Error);
    expect($result->diagnostics[0]->code)->toBe('overlay.conflicting-operation');
});

it('rejects an overlay whose actions member is not a list', function (): void {
    expect(fn () => OverlayDocument::fromArray(['overlay' => '1.0.0', 'actions' => 'nope']))
        ->toThrow(InvalidOverlayException::class);
    expect(fn () => OverlayDocument::fromArray(['overlay' => '1.0.0', 'actions' => ['target' => '$.info']]))
        ->toThrow(InvalidOverlayException::class);
});

it('lets post-overlay validation catch an update that injects a schema-invalid member', function (): void {
    // An overlay may write anything; the pipeline's validation step is the safety net.
    $overlay = overlayWith([
        ['target' => '$.info.title', 'update' => 12345],
    ]);

    $result = (new OverlayApplier)->apply(workedExample(), $overlay);

    $validation = (new Validator)->validate($result->document);

    expect($validation->isValid())->toBeFalse();
});

it('applies multiple actions in order', function (): void {
    $overlay = overlayWith([
        ['target' => "\$.paths['/api/v1/forms'].get.summary", 'update' => 'First'],
        // A new leaf member is added by merging on the parent object (Overlay 1.0: a target that
        // resolves to zero nodes is ignored, so `.description` cannot be targeted while absent).
        ['target' => "\$.paths['/api/v1/forms'].get", 'update' => ['description' => 'Second']],
        ['target' => "\$.paths['/api/v1/forms'].get.parameters[?(@.name=='per_page')]", 'remove' => true],
    ]);

    $result = (new OverlayApplier)->apply(overlayBaseDocument(), $overlay);
    $get = $result->document['paths']['/api/v1/forms']['get'];

    expect($get['summary'])->toBe('First');
    expect($get['description'])->toBe('Second');
    expect($get['parameters'])->toHaveCount(1);
    expect($result->diagnostics)->toBe([]);
});
