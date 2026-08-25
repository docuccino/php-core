<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Extensions\Schema\SchemaUnion;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * A union → its members converted and handed to {@see SchemaUnion}, which owns how the document
 * expresses a set: a nullable type-array for a single type plus null (`type: [string, null]`, the JSON
 * Schema 2020-12 idiom), else an `anyOf` with a `{type: null}` branch when nullable.
 */
final class UnionTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof UnionT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof UnionT) {
            return null;
        }

        $hasNull = false;
        $nonNull = [];
        foreach ($type->members as $member) {
            if ($member instanceof NullT) {
                $hasNull = true;

                continue;
            }
            $nonNull[] = $member;
        }

        return new SchemaResult(SchemaUnion::of(
            array_map(static fn (DType $member): array => $context->convert($member), $nonNull),
            $hasNull,
            $context->representation()->nullable,
        ));
    }
}
