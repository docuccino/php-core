<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;

/**
 * An enum → a string schema listing its case names. The engine hands over names rather than backing
 * values, hence the slightly reduced confidence; {@see EnumSchema} runs earlier and does better
 * whenever the enum is reflectable.
 */
final class EnumTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof EnumT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof EnumT) {
            return null;
        }

        if ($type->cases === []) {
            return new SchemaResult(['type' => 'string'], 0.5);
        }

        // Codegen name hints: additive x-* members that never touch `enum` itself — one rulebook,
        // EnumDecoration. No prose is known here (nothing reflectable), so no description shapes.
        $schema = EnumDecoration::apply(
            ['type' => 'string', 'enum' => $type->cases],
            $context->representation()->enumNaming,
            $type->cases,
            [],
        );

        return new SchemaResult($schema, 0.9);
    }
}
