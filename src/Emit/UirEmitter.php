<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;

/**
 * Emits a {@see UirDocument} in full — canonicalised (member order, sorted keys, method/parameter
 * order) then serialised deterministically. It is the `full` format: the same document
 * {@see OpenApi32Emitter} writes, with the `x-docuccino` extension retained rather than stripped.
 *
 * How much provenance comes out is {@see EmitOptions::$provenance}, whose `full` shares a word with
 * the format id and nothing else — one names the artifact, the other how much trail survives in it:
 *
 * - `full` — every record, `overrode` trail included;
 * - `winners` — records kept, each `overrode` list dropped, so shadowed-value history doesn't bloat
 *   committed artifacts;
 * - `none` — provenance stripped from every `x-docuccino` member.
 *
 * {@see ProvenanceLevel::Full} is the default, so a plain `emit()` reproduces the committed goldens
 * byte-for-byte; the CLI picks `winners` for committed artifacts.
 *
 * @internal
 */
final readonly class UirEmitter implements ReportingEmitter
{
    public function __construct(
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
    ) {}

    public function format(): string
    {
        return 'full';
    }

    public function emit(UirDocument $document, EmitOptions $options = new EmitOptions(provenance: ProvenanceLevel::Full)): string
    {
        return $this->emitArray($document->toArray(), $options);
    }

    /** UIR emission is never lossy — it is the full document — so the report is always empty. */
    public function emitWithReport(UirDocument $document, EmitOptions $options = new EmitOptions(provenance: ProvenanceLevel::Full)): EmitResult
    {
        return new EmitResult($this->emit($document, $options), new EmitReport);
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

    /** Applies the provenance level to every `x-docuccino` member in the tree. */
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

            // Winners: keep the records, drop the shadowed-value trail.
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
