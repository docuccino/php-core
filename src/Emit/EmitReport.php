<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * Diagnostics collected during emission (e.g. lossy downlevel transforms). Ordering is the
 * insertion order in which transforms run, which is deterministic for a given document.
 */
final readonly class EmitReport
{
    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    public function __construct(
        public array $diagnostics = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->diagnostics === [];
    }

    /**
     * Whether an emitter reported the artifact is not a valid document of the format it claims.
     *
     * An emitter raises an Error only for a defect of OURS, so this is not a question the reader gets
     * a say over: every command that produces an artifact fails on it below the reach of `--fail-on`,
     * and this is the one place that says what "fails on it" reads.
     */
    public function hasError(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Diagnostic>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (Diagnostic $d): bool => $d->severity === Severity::Warning,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Diagnostic $d): array => $d->toArray(), $this->diagnostics);
    }
}
