<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use JsonException;
use stdClass;

/**
 * The ONE reader that pulls JSON into a document, so two callers cannot come to different conclusions
 * about the same bytes ({@see tests/tools/JsonReaderArchTest.php} enforces it).
 *
 * An object becomes an associative array, except the two a PHP array cannot represent — `{}` and an
 * object whose member names re-key to a `0..n-1` run — which stay {@see stdClass}. Each is minted
 * FRESH, so equality at those positions is asked by value ({@see same()}) and never with `===`.
 *
 * Why that set and no wider, why one reader, and why no shared instance is available:
 * docs/design/uir-and-extensions.md §1 "The empty-object invariant".
 *
 * @internal
 */
final class JsonValue
{
    /** How deep {@see same()} descends before it stops; the same bound, and for the same reason, as {@see Json}. */
    private const int MAX_DEPTH = 128;

    /**
     * @throws JsonException when the string is not JSON
     */
    public static function decode(string $json): mixed
    {
        return self::normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * The same reading for a value the caller decoded itself — which is how a caller that has to
     * CLASSIFY the literal before publishing it gets both answers off one decode.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = array_map(self::normalize(...), (array) $value);

            return array_is_list($members) ? (object) $members : $members;
        }

        return is_array($value) ? array_map(self::normalize(...), $value) : $value;
    }

    /**
     * Whether two values say the same thing: `===` in every respect EXCEPT that two `stdClass` standing
     * for the same JSON object are one value however they were minted. Nothing else is relaxed, and
     * {@see Json::stable()} cannot stand in — see the design section named on the class.
     */
    public static function same(mixed $a, mixed $b, int $depth = 0): bool
    {
        if ($a === $b) {
            return true;
        }

        // A value nested deeper than any real document goes is likelier self-referential than equal, and
        // recursing into one is a stack overflow rather than an exception. "Different" is the harmless
        // answer: the patch guard's cost for it is one `overrode` entry too many, never one too few.
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        if ($a instanceof stdClass && $b instanceof stdClass) {
            return self::same(get_object_vars($a), get_object_vars($b), $depth + 1);
        }

        if (! is_array($a) || ! is_array($b) || array_keys($a) !== array_keys($b)) {
            return false;
        }

        foreach ($a as $key => $value) {
            if (! self::same($value, $b[$key], $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
