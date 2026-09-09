<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\RouteDescriptor;

/**
 * Decides whether one discovered route belongs in a document, asked after the include/exclude
 * wildcards have had their say. The adapter resolves the class named by `routes.filter` out of the
 * container, so a filter declares its dependencies in its constructor.
 *
 * Returning false omits the route entirely — no operation, no diagnostic — so a filter is a statement
 * about the API's surface rather than about the analysis. That is also why a filter that cannot be
 * built stops the run instead of being skipped: skipping it publishes every route the wildcards
 * admitted, which is the opposite of what the class was there to say.
 */
interface RouteFilter
{
    public function includes(RouteDescriptor $route): bool;
}
