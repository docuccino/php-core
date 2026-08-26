<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\IntersectionT;

/**
 * An intersection → `allOf` of its converted members.
 */
final class IntersectionTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof IntersectionT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof IntersectionT) {
            return null;
        }

        return new SchemaResult([
            'allOf' => array_map(
                static fn (DType $member): array => $context->convertMember($member),
                $type->members,
            ),
        ]);
    }
}
