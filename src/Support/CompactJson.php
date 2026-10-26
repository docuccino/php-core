<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Closure;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use stdClass;

/**
 * How many bytes a value takes as compact JSON: no whitespace, `/` and non-ASCII unescaped, floats at
 * their shortest round trip whatever the host's `serialize_precision` — {@see CanonicalJsonSerializer}'s
 * bytes with the whitespace taken out. It is a document's size rather than its text's, so no layout,
 * comment or indent moves it, and both of the reader's and the differ's budgets are measured in it.
 *
 * @internal
 */
final class CompactJson
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** The bytes $value takes, written out to count them. */
    public static function length(mixed $value): int
    {
        return self::pinned(static fn (): int => strlen((string) json_encode($value, self::FLAGS)));
    }

    /**
     * The bytes $value takes, or $enough where it takes at least that many: counted a member at a time
     * rather than written, and no further than $enough. A YAML alias naming one long string again and again
     * is gigabytes written out, and this reads as much of it as the answer needs.
     */
    public static function lengthUpTo(mixed $value, int $enough): int
    {
        return self::pinned(static function () use ($value, $enough): int {
            $length = 0;
            self::count($value, $length, $enough);

            return min($length, $enough);
        });
    }

    /** Adds $value's bytes to $length, stopping once it reaches $enough. */
    private static function count(mixed $value, int &$length, int $enough): void
    {
        if (! is_array($value) && ! $value instanceof stdClass) {
            $length += strlen((string) json_encode($value, self::FLAGS));

            return;
        }

        $map = ! is_array($value) || ! array_is_list($value);
        $first = true;

        // The opening bracket now, the closing one at the end, and a comma before every member but the first.
        $length++;
        foreach ($value instanceof stdClass ? get_object_vars($value) : $value as $name => $member) {
            if (! $first) {
                $length++;
            }
            $first = false;

            if ($map) {
                $length += strlen((string) json_encode((string) $name, self::FLAGS)) + 1;
            }

            self::count($member, $length, $enough);

            if ($length >= $enough) {
                return;
            }
        }
        $length++;
    }

    /**
     * `json_encode` writes a float at the host's `serialize_precision`, so the count runs pinned to `-1`,
     * the shortest round trip, as {@see CanonicalJsonSerializer} writes one.
     *
     * @param  Closure(): int  $count
     */
    private static function pinned(Closure $count): int
    {
        $previous = ini_set('serialize_precision', '-1');

        try {
            return $count();
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
    }
}
