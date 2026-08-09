<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\TypeToSchema;

/**
 * The default, kind-disjoint {@see TypeToSchema} chain covering the whole closed DType set. The
 * terminal {@see UnknownTypeToSchema} is registered last so a specific mapper always wins first;
 * user mappers slot ahead of these via `#[ExtensionOrder(before: …)]`.
 */
final class DefaultTypeMappers
{
    /**
     * @return list<TypeToSchema>
     */
    public static function all(): array
    {
        return [
            new ScalarTypeToSchema,
            new LiteralTypeToSchema,
            new StatusMarkerTypeToSchema,
            new EnumTypeToSchema,
            new ArrayShapeTypeToSchema,
            new CollectionTypeToSchema,
            new UnionTypeToSchema,
            new IntersectionTypeToSchema,
            new NullTypeToSchema,
            new ClassTypeToSchema,
            new UnknownTypeToSchema,
        ];
    }
}
