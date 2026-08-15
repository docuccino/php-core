<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * A gated seam typing a path parameter bound on a NAMED column rather than on the model's route key —
 * Laravel's `{post:slug}`. Its own chain, resolved per-document, first non-null wins.
 *
 * Returning null is the honest answer whenever the column can't be typed: the caller then documents a
 * plain string and says so. The {@see RouteBindingSchemaResolver} chain is deliberately NOT a fallback
 * here — the route key is a different column, so its schema (an integer `id`, typically) would be a
 * confidently wrong type for a slug rather than a weak one.
 */
interface RouteBindingFieldSchemaResolver
{
    /**
     * The JSON-schema keywords for `$field` on `$modelFqcn`, or null to defer (→ string fallback).
     *
     * @return array<string, mixed>|null
     */
    public function fieldSchemaFor(RouteContext $context, string $modelFqcn, string $field): ?array;
}
