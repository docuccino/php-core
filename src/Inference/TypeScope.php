<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;
use PhpParser\Node;

/**
 * The only type-engine-touching surface a {@see TraceVisitor} sees. A `PhpParser\Node` may cross this
 * boundary — both sides use that library — but `PHPStan\Type\*` and `Scope` never do.
 */
interface TypeScope
{
    /** The translated type of an expression. */
    public function typeOf(Node\Expr $expr): DType;

    /**
     * Recover a constant value from an expression — literals *and* factory call-descriptors like
     * `AllowedFilter::exact('status')`. Null when nothing constant is recoverable.
     */
    public function constantValueOf(Node\Expr $expr): ?ConstValue;

    /** Where a node sits in source. */
    public function location(Node $node): SourceLocation;
}
