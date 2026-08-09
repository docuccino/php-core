<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;

/**
 * An optional capability a {@see TraceVisitor} may implement to widen where the engine descends.
 *
 * By default the engine descends only into callees whose file lies under the configured project
 * paths (`['app']` by default). A modular application places code elsewhere — e.g. a
 * `Modules\Billing\Queries\InvoiceIndexQuery` whose `query()` builds the Spatie
 * `QueryBuilder::for(...)->allowedFilters(...)` chain, reached from the controller via
 * `$query->query()->paginate(...)`. Without this capability the allow-lists in that query class are
 * invisible (the descent into `$query->query()` is declined), so the filters vanish silently.
 *
 * A visitor that follows a callee's RETURN TYPE (a Query-Builder visitor follows a Spatie
 * `QueryBuilder` subclass) lets the engine descend into such a callee even outside the project
 * paths — but NEVER into vendor code (that boundary is enforced independently by the engine),
 * and still bounded by the usual depth/file budget.
 */
interface FollowsReturnType
{
    /**
     * Whether descent into a callee whose resolved return type is `$returnType` is warranted even
     * when the callee lies outside the configured project paths.
     */
    public function followsReturnType(DType $returnType): bool;
}
