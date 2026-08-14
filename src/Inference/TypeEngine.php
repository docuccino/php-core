<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The framework-agnostic inference boundary. Implementations embed a real type system (see
 * `docuccino/inference-phpstan`) but expose only serializable {@see DType\DType} results — never a
 * PHPStan `Type` — so results serialize and cross process boundaries unchanged. Design detail:
 * docs/design/inference-embedding.md §4.
 *
 * Every method is total: never throw out of one. On internal failure, return a well-formed result
 * carrying `UnknownT` plus a diagnostic. {@see NullTypeEngine} is the trivial totalising fallback.
 */
interface TypeEngine
{
    /** Analyse every return path + escaping exception of an action. */
    public function analyzeAction(ActionRef $action): ActionAnalysis;

    /**
     * Analyse a non-action callable — an exception handler's `render()`, an exception's own
     * `render()`/`toResponse()`, or a render-callback closure. When the {@see CallableRef} carries a
     * narrowing request, only the return path reachable when the named parameter is the narrowed
     * exception type is harvested (source-order first match over PHPStan's `instanceof` narrowing),
     * so a catch-all `render(Throwable $e)` yields one exception type's response per call. Total,
     * like {@see analyzeAction()}.
     */
    public function analyzeCallable(CallableRef $callable): ActionAnalysis;

    /** Expand a class's shape (properties, docblocks); lazy + memoised. */
    public function classMetadata(ClassRef $class): ClassMetadata;

    /**
     * Drive a bounded, interprocedural walk from an action. The visitor harvests as it goes, and the
     * returned {@see TraceReport} carries every file the walk read — a fragment cache keys on that,
     * since a walk N files deep must invalidate when any of them change.
     */
    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport;
}
