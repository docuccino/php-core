<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use PhpParser\Node;

/**
 * An optional capability the {@see TypeScope} handed to a {@see TraceVisitor} may implement: folding the
 * value a call RETURNS, as opposed to the arguments it is WRITTEN with — for a public name that lives in
 * the callee's BODY (`$this->termFilter()`), which {@see TypeScope::constantValueOf()} cannot reach. Why
 * it is deferred and why it is opt-in per visitor: docs/design/inference-embedding.md §4.
 *
 * The contract: `false` means nothing was queued (the callee is vendor, unresolvable, or over budget) and
 * the caller should degrade now; `true` means EXACTLY ONE `$onFolded` call follows, before the trace
 * returns, possibly with nulls when the fold failed.
 */
interface FoldsCallReturns
{
    /**
     * Ask the engine to fold what `$call` returns.
     *
     * `$onFolded` receives the folded value and the callee's returned EXPRESSION. That node belongs to
     * another file, so it may only be read as AST (a closure's body, say) — never typed against the scope
     * that handed it over.
     *
     * @param  callable(?ConstValue, ?Node\Expr): void  $onFolded
     * @return bool whether the request was queued
     */
    public function deferReturnFold(Node\Expr $call, callable $onFolded): bool;
}
