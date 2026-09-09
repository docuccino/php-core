<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * How a configured value is read back to its author. One home, because every reader that refuses a
 * value has to name what it found, and two readers describing the same float differently would make
 * the diagnostics unreadable as a set.
 *
 * Everything goes through {@see PlainText} because every byte here came out of a file: a setting can
 * hold anything somebody typed, and a diagnostic goes to a terminal and to CI logs.
 *
 * @internal
 */
final class ConfiguredValue
{
    /** What was written, as a phrase naming both the type and — for a scalar — the value itself. */
    public static function described(mixed $value): string
    {
        return match (true) {
            $value === null => 'empty',
            is_bool($value) => sprintf('the boolean %s', $value ? 'true' : 'false'),
            is_int($value) => sprintf('the whole number %s', self::rendered($value)),
            is_float($value) => sprintf('the decimal number %s', self::rendered($value)),
            is_string($value) => sprintf('the text %s', self::rendered($value)),
            is_array($value) => array_is_list($value) ? 'a list' : 'a map',
            default => 'a value of a kind YAML has no notation for',
        };
    }

    /** A value as it should be read back to its author — JSON notation, escaped for a terminal. */
    public static function rendered(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return PlainText::of($json === false ? '(unprintable)' : $json);
    }
}
