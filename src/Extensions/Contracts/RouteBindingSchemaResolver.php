<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * A gated seam typing a route-model-bound path parameter from the bound model's route key (uuid, ulid,
 * string, integer), contributed by the Eloquent integration. Resolved per-document, first non-null
 * wins, so with the integration disabled the built-in path-parameters extension falls back to a plain
 * string rather than importing a model reflector.
 */
interface RouteBindingSchemaResolver
{
    /**
     * The JSON-schema keywords for the bound model's route key, or null to defer (→ string fallback).
     *
     * @return array<string, mixed>|null
     */
    public function keySchemaFor(string $modelFqcn): ?array;
}
