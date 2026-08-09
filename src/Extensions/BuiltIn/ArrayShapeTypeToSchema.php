<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * A constant array shape → an object schema (keyed shapes, the common `response()->json([…])`
 * case) or an array schema (positional list shapes). Field order is preserved; non-optional
 * keys become `required`.
 */
final class ArrayShapeTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof ArrayShapeT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ArrayShapeT) {
            return null;
        }

        if ($type->isList) {
            $memberTypes = array_map(static fn (ArrayShapeField $field): DType => $field->type, $type->fields);

            return new SchemaResult([
                'type' => 'array',
                'items' => $memberTypes === [] ? [] : $context->convert(UnionT::of($memberTypes)),
            ]);
        }

        $properties = [];
        $required = [];
        foreach ($type->fields as $field) {
            $key = (string) $field->key;
            $properties[$key] = $context->convert($field->type);
            if (! $field->optional) {
                $required[] = $key;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return new SchemaResult($schema);
    }
}
