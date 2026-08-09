<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use PhpParser\Node;

/**
 * An interactive walk over an action's call graph. The engine walks every node
 * of each entered method (with its flow-refined {@see TypeScope}) and asks the
 * visitor about each one; harvesting happens as a side effect inside
 * `enterNode`, through the `TypeScope`.
 *
 * Responsibility split (Spike B): the visitor is pure semantics + harvesting and
 * imports zero PHPStan; the ENGINE owns bounded depth, per-`class::method`
 * memoisation, the cycle guard, callee resolution, per-file parser priming, and
 * deterministic descent ordering.
 *
 * Returning `true` is a *request* to descend into the node's callee that the
 * engine may decline (vendor code, magic/forwarded calls, unresolvable callees,
 * or depth/budget exceeded).
 */
interface TraceVisitor
{
    public function enterNode(Node $node, TypeScope $scope): bool;
}
