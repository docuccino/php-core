<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;

/**
 * A standalone `null` type → `{type: null}` (nullability inside a union is handled by
 * {@see UnionTypeToSchema}).
 */
final class NullTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof NullT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof NullT) {
            return null;
        }

        return new SchemaResult(['type' => 'null']);
    }
}
