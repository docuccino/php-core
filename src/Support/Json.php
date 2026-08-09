<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;

/**
 * A deterministic, order-insensitive JSON encoder for equality and fingerprinting. Not for canonical
 * document emission — that's {@see CanonicalJsonSerializer}.
 *
 * Arrays are re-keyed in ascending key order before encoding, so structurally-equal values encode to
 * the same bytes whatever their insertion order. List keys (`0,1,2,…`) are already ordered, so element
 * order survives. Objects collapse to their class-string, since closures and mappers have no stable
 * serialisable identity.
 *
 * @internal
 */
final class Json
{
    /** A stable string fingerprint of an arbitrary JSON-ish value; `''` when it cannot be encoded. */
    public static function stable(mixed $value): string
    {
        $encoded = json_encode(self::normalize($value));

        return $encoded === false ? '' : $encoded;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $keys = array_keys($value);
            sort($keys);
            $out = [];
            foreach ($keys as $key) {
                $out[(string) $key] = self::normalize($value[$key]);
            }

            return $out;
        }

        return is_object($value) ? $value::class : $value;
    }
}
