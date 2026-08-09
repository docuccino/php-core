<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use PhpParser\Node;

/**
 * An interactive walk over an action's call graph. The engine visits every node of each entered
 * method with its flow-refined {@see TypeScope}; harvesting happens as a side effect inside
 * `enterNode`, through that scope.
 *
 * The split: a visitor is pure semantics plus harvesting and imports zero PHPStan. The engine owns
 * bounded depth, per-`class::method` memoisation, the cycle guard, callee resolution, per-file parser
 * priming and deterministic descent ordering.
 *
 * Returning `true` *requests* a descent into the node's callee; the engine may decline it (vendor
 * code, magic/forwarded calls, unresolvable callees, depth or budget exceeded).
 */
interface TraceVisitor
{
    public function enterNode(Node $node, TypeScope $scope): bool;
}
