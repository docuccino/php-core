<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * A union → a nullable type-array when it's a single type plus null (`type: [string, null]`, the JSON
 * Schema 2020-12 idiom), else an `anyOf` of its members with a `{type: null}` branch when nullable.
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

        if (count($nonNull) === 1) {
            $schema = $context->convert($nonNull[0]);

            if ($hasNull) {
                $schema = self::makeNullable($schema, $context->representation()->nullable);
            }

            return new SchemaResult($schema);
        }

        $anyOf = array_map(static fn (DType $member): array => $context->convert($member), $nonNull);
        if ($hasNull) {
            $anyOf[] = ['type' => 'null'];
        }

        return new SchemaResult(['anyOf' => $anyOf]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function makeNullable(array $schema, string $policy): array
    {
        $type = $schema['type'] ?? null;

        // `type-array` folds null into a simple typed schema; `anyof` always uses an explicit branch.
        if ($policy !== 'anyof' && is_string($type)) {
            $schema['type'] = [$type, 'null'];

            return $schema;
        }

        // Not a simple typed schema (a $ref or anyOf), or the anyof policy — use a branch.
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }
}
