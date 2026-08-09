<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;

/**
 * The UIR emitter's provenance levels (design §4). `full` is the emitter default and reproduces the
 * committed golden byte-for-byte; `winners` keeps records but drops `overrode`; `none` strips
 * provenance entirely. Option-level fixtures live beside the goldens and are byte-checked here.
 */
function emitWorked(ProvenanceLevel $level): string
{
    $document = UirDocument::fromArray(loadFixture('worked-example.json'));

    return (new UirEmitter)->emit($document, (new EmitOptions)->withProvenance($level));
}

it('defaults to full provenance, matching the committed golden', function (): void {
    $document = UirDocument::fromArray(loadFixture('worked-example.json'));

    expect((new UirEmitter)->emit($document))->toBe(loadGolden('worked-example.uir.json'));
    expect(emitWorked(ProvenanceLevel::Full))->toBe(loadGolden('worked-example.uir.json'));
});

it('drops overrode trails but keeps records at the winners level', function (): void {
    $output = emitWorked(ProvenanceLevel::Winners);

    expect($output)->toBe(loadGolden('worked-example.uir.winners.json'));
    expect($output)->toContain('"provenance"');
    expect($output)->not->toContain('"overrode"');
});

it('strips provenance arrays entirely at the none level', function (): void {
    $output = emitWorked(ProvenanceLevel::None);

    expect($output)->toBe(loadGolden('worked-example.uir.none.json'));
    expect($output)->not->toContain('"provenance"');
    expect($output)->not->toContain('"overrode"');
    // Non-provenance x-docuccino members (ids) survive the strip.
    expect($output)->toContain('"id": "op:v1:mfz3q8k2w9r7t1ua"');
});

it('exposes withProvenance as a wither without mutating the original options', function (): void {
    $base = new EmitOptions;
    $winners = $base->withProvenance(ProvenanceLevel::Winners);

    expect($base->provenance)->toBe(ProvenanceLevel::None);
    expect($winners->provenance)->toBe(ProvenanceLevel::Winners);
    expect($winners->keepIds)->toBe($base->keepIds);
});
