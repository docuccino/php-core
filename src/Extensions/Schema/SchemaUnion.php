<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;

/**
 * The one expression of "this value is any of these shapes" — a set of already-built member schemas
 * assembled under the document's `nullable` policy.
 *
 * A producer that special-cases ONE member of a union — a serialised date-time, a cast column's wire
 * shape — must contribute that member here rather than return it in place of the union, or the schema
 * forbids a value the API really sends and a generated client types the property as the special case
 * alone. Assembling from a member list makes losing one impossible by construction, which is why the
 * union mapper and every producer holding a hand-built member go through this instead of re-deriving
 * the shape.
 */
final class SchemaUnion
{
    /**
     * The members, plus `null` when the value admits it: one member folds into a nullable type where
     * the policy and the member's own shape allow, more than one becomes an `anyOf`. Member order is
     * the caller's, so the result is a function of the type set and not of what was met first.
     *
     * @param  list<array<string, mixed>>  $members  the non-null members
     * @param  string  $policy  {@see RepresentationPolicy::$nullable}
     * @return array<string, mixed>
     */
    public static function of(array $members, bool $nullable, string $policy = 'type-array'): array
    {
        if ($members === []) {
            // Nothing concrete survived: `null` alone is still true, and an empty set says nothing.
            return $nullable ? ['type' => 'null'] : [];
        }

        if (count($members) === 1) {
            return $nullable ? self::nullable($members[0], $policy) : $members[0];
        }

        $anyOf = $members;
        if ($nullable) {
            $anyOf[] = ['type' => 'null'];
        }

        return ['anyOf' => $anyOf];
    }

    /**
     * One schema widened to admit `null`. A simple `type` — a name, or a list of them — folds the null
     * in under the `type-array` policy; anything that cannot carry one, a `$ref` or an `anyOf`, takes
     * an explicit branch, as does everything under the `anyof` policy. Already-nullable passes through.
     *
     * A value list that rules null out cannot take the fold ({@see valuesRefuseNull()}): it takes the
     * branch too, which also leaves an enum's positional name hints parallel to its values.
     *
     * @param  array<string, mixed>  $schema
     * @param  string  $policy  {@see RepresentationPolicy::$nullable}
     * @return array<string, mixed>
     */
    public static function nullable(array $schema, string $policy = 'type-array'): array
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? null;
        $names = self::typeNames($type);

        if ($type === 'null' || in_array('null', $names, true) || self::hasNullBranch($schema)) {
            return $schema;
        }

        if ($policy !== 'anyof' && ! self::valuesRefuseNull($schema)) {
            if (is_string($type)) {
                $schema['type'] = [$type, 'null'];

                return $schema;
            }

            if (is_array($type)) {
                $schema['type'] = [...$names, 'null'];

                return $schema;
            }
        }

        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    /**
     * Whether an `enum` or `const` on the schema rules null out. Either constrains the value beside
     * `type`, so no nullable spelling that leaves it in place admits null — the ONE reading every site
     * widening a schema to null asks ({@see FieldNode} is the validation side's).
     *
     * @param  array<string, mixed>  $schema
     */
    public static function valuesRefuseNull(array $schema): bool
    {
        $enum = $schema['enum'] ?? null;
        if (is_array($enum) && ! in_array(null, $enum, true)) {
            return true;
        }

        return array_key_exists('const', $schema) && $schema['const'] !== null;
    }

    /**
     * Whether the schema is already a composition with a `{type: null}` branch — what a second widening
     * of a branched fragment is handed, and must not nest.
     *
     * @param  array<string, mixed>  $schema
     */
    private static function hasNullBranch(array $schema): bool
    {
        $branches = $schema['anyOf'] ?? null;

        return is_array($branches) && in_array(['type' => 'null'], $branches, true);
    }

    /**
     * A `type` keyword given as a list, as that list; anything else as empty.
     *
     * @return list<mixed>
     */
    private static function typeNames(mixed $type): array
    {
        return is_array($type) ? array_values($type) : [];
    }
}
