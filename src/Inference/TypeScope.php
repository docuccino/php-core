<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;
use PhpParser\Node;

/**
 * The only type-engine-touching surface a {@see TraceVisitor} sees. A
 * `PhpParser\Node` crosses this boundary (a stable library both sides use); the
 * underlying `PHPStan\Type\*` / `Scope` never do (design §4).
 */
interface TypeScope
{
    /** The translated type of an expression. */
    public function typeOf(Node\Expr $expr): DType;

    /**
     * Recover a constant value from an expression — literals *and* factory
     * call-descriptors (`AllowedFilter::exact('status')`). Returns null when
     * nothing constant is recoverable.
     */
    public function constantValueOf(Node\Expr $expr): ?ConstValue;

    /** Where a node sits in source. */
    public function location(Node $node): SourceLocation;
}
