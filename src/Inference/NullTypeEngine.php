<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The totalising fallback engine: it knows nothing, resolves everything to
 * "unknown", and never throws — so consumers stay total even when the real
 * engine fails to boot (engine-level boot failure → `NullTypeEngine`, design
 * §3, so docblock/attribute-only docs still build).
 */
final readonly class NullTypeEngine implements TypeEngine
{
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
