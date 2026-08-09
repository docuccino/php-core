<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Support\Hydrate;

/**
 * The result of {@see TypeEngine::analyzeAction()}: every return path's type,
 * every escaping exception, any diagnostics, and the transitive set of files
 * the analysis read.
 *
 * `dependencyFiles` feeds the pipeline's OperationFragment cache key — sound
 * even for a Query class three calls deep (plan §Caching). Serialization is
 * canonical (`dependencyFiles` sorted + deduped) so two runs of the same code
 * produce byte-identical output — the engine's determinism invariant.
 */
final readonly class ActionAnalysis
{
    /**
     * @param  list<ReturnSite>  $returns
     * @param  list<ThrownException>  $throws
     * @param  list<Diagnostic>  $diagnostics
     * @param  list<string>  $dependencyFiles
     */
    public function __construct(
        public array $returns = [],
        public array $throws = [],
        public array $diagnostics = [],
        public array $dependencyFiles = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $deps = array_values(array_unique($this->dependencyFiles));
        sort($deps);

        return [
            'returns' => array_map(static fn (ReturnSite $r): array => $r->toArray(), $this->returns),
            'throws' => array_map(static fn (ThrownException $t): array => $t->toArray(), $this->throws),
            'diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $this->diagnostics),
            'dependencyFiles' => $deps,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $deps = $data['dependencyFiles'] ?? [];

        return new self(
            Hydrate::listOf($data['returns'] ?? null, ReturnSite::fromArray(...)),
            Hydrate::listOf($data['throws'] ?? null, ThrownException::fromArray(...)),
            Hydrate::listOf($data['diagnostics'] ?? null, Diagnostic::fromArray(...)),
            is_array($deps) ? array_values(array_filter($deps, 'is_string')) : [],
        );
    }
}
