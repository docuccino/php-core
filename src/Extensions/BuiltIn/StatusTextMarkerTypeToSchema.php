<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\StatusTextMarkerT;

/**
 * A {@see StatusTextMarkerT} → the schema of the type it was read as, unchanged: which phrase it holds
 * depends on the status, so the schema states only what every status shares, and the phrase itself is
 * the response seam's to illustrate.
 */
final class StatusTextMarkerTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof StatusTextMarkerT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof StatusTextMarkerT) {
            return null;
        }

        return new SchemaResult($context->convertMember($type->type));
    }
}
