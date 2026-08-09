<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\StatusMarkerT;

/**
 * A {@see StatusMarkerT} → a bare `integer` schema. The response-building seam normally resolves the
 * marker to a concrete {@see LiteralT} (the response's own status)
 * BEFORE conversion, so a `const`-pinned integer is emitted instead; this mapper is the total, HONEST
 * fallback for an unresolved marker — an integer with NO `const` and NO example, never a fabricated
 * status.
 */
final class StatusMarkerTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof StatusMarkerT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof StatusMarkerT) {
            return null;
        }

        return new SchemaResult(['type' => 'integer']);
    }
}
