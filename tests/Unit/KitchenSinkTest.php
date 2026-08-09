<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Validation\Validator;

/**
 * The maximal-document battery (QA H3). The kitchen-sink fixture exercises every modelled UIR
 * surface; running it through the whole pipeline guards against any member silently vanishing or
 * a construct breaking canonicalisation/validation/emission. The `stripDocuccino` helper is shared
 * with OpenApi32EmitterTest.
 */
it('validates against the bundled UIR schema', function (): void {
    $validation = (new Validator)->validate(kitchenSink());

    expect($validation->isValid())->toBeTrue()
        ->and($validation->errors)->toBe([]);
});

it('is canonicalisation-idempotent', function (): void {
    $emitter = new UirEmitter;

    $once = $emitter->emitArray(kitchenSink());
    $reparsed = json_decode($once, true, flags: JSON_THROW_ON_ERROR);

    expect($emitter->emitArray($reparsed))->toBe($once);
});

it('round-trips losslessly: OAS 3.2 output equals the x-docuccino-stripped canonical UIR', function (): void {
    $uir = kitchenSink();

    $oas = (new OpenApi32Emitter)->emit(UirDocument::fromArray($uir));

    $stripped = stripDocuccino($uir);
    unset($stripped['$schema'], $stripped['uir']);

    $expected = (new CanonicalJsonSerializer)->serialize((new Canonicalizer)->canonicalize($stripped));

    expect($oas)->toBe($expected);
});

it('keeps every top-level surface through the OAS 3.2 round trip', function (): void {
    $oas = json_decode((new OpenApi32Emitter)->emit(UirDocument::fromArray(kitchenSink())), true, flags: JSON_THROW_ON_ERROR);

    // No modelled member silently vanished.
    expect($oas)->toHaveKey('servers');
    expect($oas)->toHaveKey('webhooks');
    expect($oas['components'])->toHaveKey('securitySchemes');
    expect($oas['paths']['/widgets/{id}']['put'])->toHaveKey('requestBody');
    expect($oas['paths']['/widgets/{id}']['get'])->toHaveKey('security');
    // The 3.2-only query method survives in 3.2 output.
    expect($oas['paths']['/widgets/{id}'])->toHaveKey('query');
    // Unicode-keyed schema properties survive.
    expect($oas['components']['schemas']['Widget']['properties'])->toHaveKey('café');
    expect($oas['components']['schemas']['Widget']['properties'])->toHaveKey('日本語');
});

it('downlevels to 3.1, dropping the query method with a warning', function (): void {
    $result = (new OpenApi31DownlevelEmitter)->emitWithReport(UirDocument::fromArray(kitchenSink()));

    expect($result->output)->toContain('"openapi": "3.1.1"');
    expect($result->output)->not->toContain('widgets.query');

    $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
    expect($codes)->toContain('downlevel.query-method');
});

it('emits deterministic YAML across runs', function (): void {
    $document = UirDocument::fromArray(kitchenSink());
    $options = (new EmitOptions)->withYaml();

    expect((new OpenApi32Emitter)->emit($document, $options))->toBe((new OpenApi32Emitter)->emit($document, $options));
});

it('round-trips through the model preserving all members', function (): void {
    expect(UirDocument::fromArray(kitchenSink())->toArray())->toEqual(kitchenSink());
});
