<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;

/**
 * The route's entry point into the {@see TypeToSchema} chain (design §6) — what
 * `RouteContext::converter()` hands an extension. {@see toSchema()} starts a TOP-LEVEL conversion (a
 * response body, a parameter, a recovered field) and reports the lowest confidence any mapper hit
 * across it; it hoists named schemas into the document-wide component registry, so one class
 * converted from two routes is one deduped component.
 *
 * The same object is the {@see SchemaContext} mappers receive, so it passes straight to
 * {@see ValidationRulesToSchema::convert()}. From INSIDE a mapper use {@see SchemaContext::convert()}
 * instead: it recurses through the chain without restarting the confidence accumulator or the depth
 * count that {@see SchemaContext::depth()} publishes.
 */
interface TypeSchemaConverter extends SchemaContext
{
    /**
     * Convert a top-level type, returning the schema and the lowest confidence reported across it and
     * every nested conversion it triggered.
     */
    public function toSchema(DType $type): SchemaResult;
}
