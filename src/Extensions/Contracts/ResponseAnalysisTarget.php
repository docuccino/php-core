<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\ResponseAnalysisRedirect;
use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * A gated seam letting an integration point success-body inference at something other than the
 * dispatched action — a `lorisleiva/laravel-actions` action defining `jsonResponse()`, whose return
 * type is the true wire shape. Resolved per-document, so a disabled integration contributes no
 * redirect; the inferred-responses extension reads this chain and never an integration class. The
 * redirect carries its own provenance producer.
 */
interface ResponseAnalysisTarget
{
    /** The analysis redirect for this route, or null to leave the dispatched action's own analysis in place. */
    public function resolve(RouteContext $context): ?ResponseAnalysisRedirect;
}
