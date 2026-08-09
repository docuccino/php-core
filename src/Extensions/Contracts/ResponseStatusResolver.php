<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * A gated seam contributing the success-status override(s) for a returned class — e.g. a spatie Data
 * class overriding `calculateResponseStatus()` to 201/202. Resolved per-document like the
 * exception-mapper chain (first resolver returning a non-empty list wins), so a DISABLED integration
 * contributes no override and the built-in inferred-responses extension reads only this chain (never
 * the integration class).
 */
interface ResponseStatusResolver
{
    /**
     * The HTTP success status(es) this class documents, or `[]` to leave the inferred default in
     * place. Usually one; more than one when the override folds to several constants (a
     * conditional/ternary whose arms all fold — each status carries the same response body).
     *
     * @return list<int>
     */
    public function resolveStatuses(RouteContext $context, string $fqcn): array;
}
