<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ScalarT;

/**
 * A non-constant scalar → its JSON Schema `type`.
 */
final class ScalarTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof ScalarT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ScalarT) {
            return null;
        }

        return new SchemaResult(['type' => JsonTypes::forScalar($type->scalar)]);
    }
}
