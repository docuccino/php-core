<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;

/**
 * A constant scalar → a `const`-pinned schema of the matching type.
 */
final class LiteralTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof LiteralT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof LiteralT) {
            return null;
        }

        return new SchemaResult([
            'type' => JsonTypes::forScalar($type->base()),
            'const' => $type->value,
        ]);
    }
}
