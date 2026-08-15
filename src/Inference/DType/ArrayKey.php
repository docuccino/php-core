<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * Owns the `array<K, V>` list-vs-map rule for every path that recovers one — the docblock grammar and the
 * PHPStan translator both arrive here with the key already a {@see DType}, so there is one implementation.
 * Only a string key makes a PHP array serialize to a JSON object, so an int-capable key (`int`, an int
 * literal, `array-key`, `int|string`) documents a JSON array and every other key stays a {@see MapT}; the
 * tradeoff that makes for an ambiguous key is in docs/design/uir-and-extensions.md §8.
 */
final class ArrayKey
{
    /** The `array<K, V>` decision: a list for an int-capable key, else a keyed map. */
    public static function arrayOf(DType $key, DType $value): DType
    {
        return self::mayBeInt($key) ? new ListT($value) : new MapT($key, $value);
    }

    /** Whether an array with this key can carry an int key — a union counts if any member can. */
    public static function mayBeInt(DType $key): bool
    {
        if ($key instanceof UnionT) {
            foreach ($key->members as $member) {
                if (self::mayBeInt($member)) {
                    return true;
                }
            }

            return false;
        }

        return ($key instanceof ScalarT && $key->scalar === ScalarT::INT)
            || ($key instanceof LiteralT && $key->base() === ScalarT::INT);
    }
}
