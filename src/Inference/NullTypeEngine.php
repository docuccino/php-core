<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The totalising fallback engine: knows nothing, resolves everything to "unknown", never throws. When
 * the real engine fails to boot we swap this in, so docblock- and attribute-only docs still build —
 * carrying that boot error, so the swap is something a host can report and act on rather than a
 * silent loss of a whole tier of facts. Configured away or never installed, it carries nothing.
 */
final readonly class NullTypeEngine implements ReportsBootFailure, TypeEngine
{
    /** @param  string|null  $bootFailure  the error that put this engine here, if one did */
    public function __construct(private ?string $bootFailure = null) {}

    public function bootFailure(): ?string
    {
        return $this->bootFailure;
    }

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        return new ActionAnalysis(
            returns: [],
            throws: [],
            diagnostics: [],
            dependencyFiles: [$action->file],
        );
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        return new ActionAnalysis(dependencyFiles: [$callable->file]);
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return new ClassMetadata($class->fqcn);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        // Nothing to walk — no dependency files read.
        return new TraceReport;
    }
}
