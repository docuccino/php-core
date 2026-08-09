<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * A gated seam contributing success-status overrides for a returned class — a spatie Data class
 * overriding `calculateResponseStatus()` to 201, say. Resolved per-document, first non-empty list
 * wins, so a disabled integration contributes nothing; the inferred-responses extension reads this
 * chain and never an integration class.
 */
interface ResponseStatusResolver
{
    /**
     * The success status(es) this class documents, or `[]` to leave the inferred default alone. Usually
     * one; several when the override is a conditional whose arms all fold to constants, in which case
     * every status carries the same body.
     *
     * @return list<int>
     */
    public function resolveStatuses(RouteContext $context, string $fqcn): array;
}
