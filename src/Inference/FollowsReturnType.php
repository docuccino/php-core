<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;

/**
 * An optional capability a {@see TraceVisitor} may implement to widen where the engine descends.
 *
 * By default the engine only descends into callees whose file lives under the configured project
 * paths (`['app']`). Modular apps put code elsewhere — a `Modules\Billing\Queries\InvoiceIndexQuery`
 * whose `query()` builds the Spatie `QueryBuilder::for(…)->allowedFilters(…)` chain, say — and the
 * descent gets declined, so those allow-lists silently vanish from the docs.
 *
 * A visitor that recognises a callee by its return type (a Query-Builder visitor follows a Spatie
 * `QueryBuilder` subclass) lets the engine descend anyway. Never into vendor code, though — the
 * engine enforces that boundary itself — and still bounded by the usual depth/file budget.
 */
interface FollowsReturnType
{
    /**
     * Should the engine descend into a callee returning `$returnType`, even outside project paths?
     */
    public function followsReturnType(DType $returnType): bool;
}
