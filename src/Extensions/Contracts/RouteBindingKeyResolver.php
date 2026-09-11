<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * A gated seam naming the column a route-model-bound path parameter is matched ON — the bound model's
 * route key. Its own chain beside the two schema seams ({@see RouteBindingSchemaResolver},
 * {@see RouteBindingFieldSchemaResolver}), resolved per-document, first non-null wins.
 *
 * A name, not a schema, because it is published as prose and as a fact a client generator can read:
 * the schema says what SHAPE the segment is, and this says which attribute of the resource the server
 * looks it up by, which is the half a consumer cannot work out from the path.
 *
 * Null is the honest answer for anything a resolver cannot settle statically. A model that decides its
 * route key in a method body has chosen a column no reflection can read, and publishing the primary
 * key's name for one of those would tell a consumer to send an id where the server matches a slug —
 * so the caller says nothing about the key rather than guessing.
 */
interface RouteBindingKeyResolver
{
    /** The route key's column name, or null to defer (→ the parameter says nothing about its key). */
    public function keyNameFor(string $modelFqcn): ?string;
}
