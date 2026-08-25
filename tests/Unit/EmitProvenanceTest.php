<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\SpecValidation\Validator;

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

/**
 * Levelling removes members from a document the schema has already accepted at `full`, and removal is
 * where a document stops answering to its schema: a required member goes with it, or what is left of a
 * record no longer matches the shape `provenanceRecord` seals. `winners` is what `docuccino:export`
 * writes by default, so it is the emission most users actually commit — and until now the only thing
 * reading either level was a byte comparison, which cannot tell a valid document from an invalid one it
 * recorded a while ago.
 */
it('emits a document its own schema still accepts at every provenance level', function (ProvenanceLevel $level): void {
    $emitted = json_decode(emitWorked($level), true, flags: JSON_THROW_ON_ERROR);
    $validation = (new Validator)->validate($emitted);

    expect($validation->errors)->toBe([])
        ->and($validation->isValid())->toBeTrue();
})->with([
    'full' => [ProvenanceLevel::Full],
    'winners' => [ProvenanceLevel::Winners],
    'none' => [ProvenanceLevel::None],
]);

/**
 * A validation that finds nothing must fail. Each level is checked for what levelling left behind, so a
 * `withProvenance()` that quietly stopped levelling — or an emitter that started answering with an empty
 * document — fails here rather than validating three copies of the same bytes forever.
 */
it('validates three levels that are genuinely different documents', function (): void {
    $records = static function (ProvenanceLevel $level): int {
        $count = 0;
        $walk = function (mixed $node) use (&$walk, &$count): void {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if ($key === 'provenance' && is_array($value)) {
                    $count += count($value);
                }

                $walk($value);
            }
        };

        $walk(json_decode(emitWorked($level), true, flags: JSON_THROW_ON_ERROR));

        return $count;
    };

    expect($records(ProvenanceLevel::Full))->toBeGreaterThanOrEqual(2)
        ->and($records(ProvenanceLevel::Winners))->toBe($records(ProvenanceLevel::Full))
        ->and($records(ProvenanceLevel::None))->toBe(0)
        ->and(substr_count(emitWorked(ProvenanceLevel::Full), '"overrode"'))->toBeGreaterThanOrEqual(1);
});

it('exposes withProvenance as a wither without mutating the original options', function (): void {
    $base = new EmitOptions;
    $winners = $base->withProvenance(ProvenanceLevel::Winners);

    expect($base->provenance)->toBe(ProvenanceLevel::None);
    expect($winners->provenance)->toBe(ProvenanceLevel::Winners);
    expect($winners->keepIds)->toBe($base->keepIds);
});
