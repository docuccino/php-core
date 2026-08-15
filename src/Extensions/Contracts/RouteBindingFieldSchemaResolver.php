<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * A {@see RouteBindingSchemaResolver} that can also type a parameter bound on a NAMED column rather
 * than on the model's route key — Laravel's `{post:slug}`. Resolvers that only know the route key
 * implement the parent contract alone and are simply skipped for such a parameter.
 *
 * Returning null is the honest answer whenever the column can't be typed: the caller then documents a
 * plain string and says so, because the route key's schema — an integer `id`, typically — would be a
 * confidently wrong type for a slug.
 */
interface RouteBindingFieldSchemaResolver extends RouteBindingSchemaResolver
{
    /**
     * The JSON-schema keywords for `$field` on `$modelFqcn`, or null to defer (→ string fallback).
     *
     * @return array<string, mixed>|null
     */
    public function fieldSchemaFor(RouteContext $context, string $modelFqcn, string $field): ?array;
}
