<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The result of {@see TypeEngine::trace()}. Trace is interactive — the visitor
 * harvests as it walks — but the walk still reads a transitive set of files, and
 * that dependency accounting must reach the caller: the pipeline's
 * OperationFragment cache keys on these files, so a QB chain traced three files
 * deep invalidates when any of those deep files change (without this the cache
 * would serve stale docs).
 *
 * `dependencyFiles` is canonical — sorted + deduped, the same normalization as
 * {@see ActionAnalysis} — so the report is deterministic and directly usable as a
 * cache-key input. The type is deliberately a small struct (not a bare array) so
 * later diagnostics/telemetry can grow onto it without another contract break.
 */
final readonly class TraceReport
{
    /** @var list<string> */
    public array $dependencyFiles;

    /**
     * @param  list<string>  $dependencyFiles
     */
    public function __construct(array $dependencyFiles = [])
    {
        $deps = array_values(array_unique(array_filter($dependencyFiles, 'is_string')));
        sort($deps);
        $this->dependencyFiles = $deps;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['dependencyFiles' => $this->dependencyFiles];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $deps = $data['dependencyFiles'] ?? [];

        return new self(is_array($deps) ? array_values(array_filter($deps, 'is_string')) : []);
    }
}
