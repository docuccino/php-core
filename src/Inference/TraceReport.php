<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The result of {@see TypeEngine::trace()}. The visitor harvests as it walks, but the walk still
 * reads a transitive set of files and the caller needs that accounting: the OperationFragment cache
 * keys on it, so a chain traced three files deep invalidates when any of those deep files change.
 * Without it the cache would serve stale docs.
 *
 * `dependencyFiles` is sorted and deduped, same normalisation as {@see ActionAnalysis}, so the report
 * is deterministic and usable straight as a cache-key input. It's a struct rather than a bare array
 * so diagnostics or telemetry can grow onto it later without breaking the contract.
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
