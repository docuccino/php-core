<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Support\Fqcn;

/**
 * Where an enum class becomes a published component: its body, its name, its identity. One place,
 * because more than one producer reaches an enum — the type chain from a property, a validation rule
 * from a request body — and a name is a type name in somebody's generated client. Two producers
 * naming or shaping one enum differently would publish two components for one thing, which is the
 * duplication `enums.components` exists to remove.
 *
 * The body is built from the enum's own declaration order, never from the order the producer that got
 * there first happened to list its values, so what the component says is a function of the enum.
 */
final class EnumComponent
{
    /**
     * Whether this class hoists at all. Only a reflectable enum has an honest name and identity to pin;
     * everything stays inline when the policy is off.
     */
    public static function hoists(string $fqcn, SchemaContext $context): bool
    {
        return $context->representation()->enumComponents && enum_exists($fqcn);
    }

    /**
     * The published body for a reflectable enum — backing values, typed by them, decorated with the
     * case names and `#[CaseDescription]` prose. Null when the class is not a reflectable enum.
     *
     * @return array<string, mixed>|null
     */
    public static function body(string $fqcn, SchemaContext $context): ?array
    {
        $values = EnumReflection::values($fqcn);
        if ($values === []) {
            return null;
        }

        $allInt = $values === array_filter($values, 'is_int');

        return EnumDecoration::apply(
            [
                'type' => $allInt ? 'integer' : 'string',
                'enum' => $allInt ? $values : array_map(strval(...), $values),
            ],
            $context->representation()->enumNaming,
            EnumReflection::names($fqcn),
            EnumReflection::descriptions($fqcn),
        );
    }

    /**
     * The `$ref` for this enum's component, materialising `$schema` as its body.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    public static function reference(string $fqcn, array $schema, SchemaContext $context): array
    {
        /** @var array<string, string> */
        return $context->reference(
            SchemaIdentity::name($fqcn) ?? Fqcn::short($fqcn),
            $schema,
            SchemaIdentity::publishedId($fqcn),
        );
    }
}
