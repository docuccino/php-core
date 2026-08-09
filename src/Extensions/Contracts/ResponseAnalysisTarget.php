<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\ResponseAnalysisRedirect;
use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * A gated seam letting an integration redirect success-body inference to a DIFFERENT analysis target
 * than the dispatched action — e.g. a `lorisleiva/laravel-actions` action defining `jsonResponse()`,
 * whose return type is the true JSON wire shape. Resolved per-document like the exception-mapper chain,
 * so a DISABLED integration contributes no redirect and the built-in inferred-responses extension reads
 * only this chain (never an integration class). The redirect carries the honest provenance producer.
 */
interface ResponseAnalysisTarget
{
    /** The analysis redirect for this route, or null to leave the dispatched action's own analysis in place. */
    public function resolve(RouteContext $context): ?ResponseAnalysisRedirect;
}
