<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;

/**
 * A generated document plus its deterministically-ordered diagnostics.
 *
 * @internal
 */
final readonly class GenerationResult
{
    /**
     * @param  list<Diagnostic>  $diagnostics
     * @param  array<string, string>  $schemaSources  {@see AssemblyResult::$schemaSources} — carried
     *                                                through because the emitted document keeps node
     *                                                ids and nothing else, so a reader that has to name
     *                                                the class behind a schema has no other way back
     */
    public function __construct(
        public UirDocument $document,
        public array $diagnostics = [],
        public array $schemaSources = [],
    ) {}

    public function has(Severity $severity): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === $severity) {
                return true;
            }
        }

        return false;
    }

    /** True when anything was reported at $floor or louder — the raw question a severity gate starts from. */
    public function hasAtLeast(Severity $floor): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity->atLeast($floor)) {
                return true;
            }
        }

        return false;
    }
}
