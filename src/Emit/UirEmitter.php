<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;

/**
 * Emits a {@see UirDocument} as canonical UIR JSON: the document is canonicalised
 * (member order, sorted keys, method/parameter order) and serialised deterministically.
 *
 * Provenance emission honours {@see EmitOptions::$provenance} (design §4):
 *
 * - `full` (the emitter default) — every provenance record, including its `overrode` trail;
 * - `winners` — the records are kept but each `overrode` list is dropped (source lines still
 *   churn, but the shadowed-value history does not bloat committed artifacts);
 * - `none` — provenance arrays are stripped from every `x-docuccino` member entirely.
 *
 * The default is `full`, so a plain `emit()` reproduces the committed goldens byte-for-byte;
 * the CLI selects `winners` for committed artifacts.
 *
 * @internal
 */
final readonly class UirEmitter implements Emitter
{
    public function __construct(
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
    ) {}

    public function format(): string
    {
        return 'uir';
    }

    public function emit(UirDocument $document, EmitOptions $options = new EmitOptions(provenance: ProvenanceLevel::Full)): string
    {
        return $this->emitArray($document->toArray(), $options);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function emitArray(array $document, EmitOptions $options = new EmitOptions(provenance: ProvenanceLevel::Full)): string
    {
        if ($options->provenance !== ProvenanceLevel::Full) {
            /** @var array<string, mixed> $document */
            $document = $this->levelProvenance($document, $options->provenance);
        }

        return $this->serializer->serialize($this->canonicalizer->canonicalize($document));
    }

    /**
     * Walk the document, applying the provenance level to every `x-docuccino` member.
     */
    private function levelProvenance(mixed $node, ProvenanceLevel $level): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = $key === 'x-docuccino' && is_array($value)
                ? $this->levelDocuccino($value, $level)
                : $this->levelProvenance($value, $level);
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $docuccino
     * @return array<array-key, mixed>
     */
    private function levelDocuccino(array $docuccino, ProvenanceLevel $level): array
    {
        $out = [];
        foreach ($docuccino as $key => $value) {
            if ($key !== 'provenance') {
                $out[$key] = $this->levelProvenance($value, $level);

                continue;
            }

            if ($level === ProvenanceLevel::None) {
                continue; // strip the provenance array entirely
            }

            // Winners: keep every record but drop its `overrode` shadowed-value trail.
            $out[$key] = is_array($value)
                ? array_map($this->dropOverrode(...), $value)
                : $value;
        }

        return $out;
    }

    private function dropOverrode(mixed $record): mixed
    {
        if (is_array($record)) {
            unset($record['overrode']);
        }

        return $record;
    }
}
