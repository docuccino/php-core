<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;

/**
 * A deterministic, in-process {@see TypeEngine} for unit tests: it answers `analyzeAction`
 * and `classMetadata` from canned maps keyed by action symbol / class FQCN, and returns an
 * empty analysis / metadata for anything unknown. No PHPStan involved.
 */
final class StubTypeEngine implements TypeEngine
{
    /**
     * @param  array<string, ActionAnalysis>  $analyses  keyed by ActionRef::symbol()
     * @param  array<string, ClassMetadata>  $classes  keyed by FQCN
     * @param  array<string, callable(TraceVisitor): void>  $traces  keyed by ActionRef::symbol(): a
     *                                                               scripted walk that drives the visitor deterministically (stands in for the real trace so a
     *                                                               trace-based integration, e.g. the Query Builder, can be exercised in-process)
     * @param  array<string, ActionAnalysis>  $callables  keyed by CallableRef::symbol(): scripted
     *                                                    handler/closure analyses (stands in for the real narrowed catch-all recovery in-process)
     */
    public function __construct(
        private array $analyses = [],
        private array $classes = [],
        private array $traces = [],
        private array $callables = [],
    ) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        return $this->analyses[$action->symbol()] ?? new ActionAnalysis(dependencyFiles: [$action->file]);
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        return $this->callables[$callable->symbol()] ?? new ActionAnalysis(dependencyFiles: [$callable->file]);
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->classes[$class->fqcn] ?? new ClassMetadata($class->fqcn);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        $script = $this->traces[$action->symbol()] ?? null;
        if ($script !== null) {
            $script($visitor);
        }

        return new TraceReport($action->file === '' ? [] : [$action->file]);
    }
}
