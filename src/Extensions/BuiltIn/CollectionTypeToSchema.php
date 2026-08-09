<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;

/**
 * A non-constant `list<V>` → array schema; a non-constant `array<K, V>` → object schema with
 * `additionalProperties`.
 */
final class CollectionTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof ListT || $type instanceof MapT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if ($type instanceof ListT) {
            return new SchemaResult([
                'type' => 'array',
                'items' => $context->convert($type->value),
            ]);
        }

        if ($type instanceof MapT) {
            return new SchemaResult([
                'type' => 'object',
                'additionalProperties' => $context->convert($type->value),
            ]);
        }

        return null;
    }
}
