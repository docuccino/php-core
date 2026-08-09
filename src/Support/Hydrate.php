<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Hydration helpers for the JSON boundary. Decoded UIR/OAS data is typed
 * `array<mixed, mixed>`; these coerce individual members to the narrow shapes
 * the immutable document model expects, dropping anything malformed. Every
 * `fromArray()` in the model reaches for one of these instead of hand-rolling
 * the same guarded loops.
 *
 * @internal
 */
final class Hydrate
{
    public static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * A string value, coercing any other scalar (int/float/bool) to its string form, and falling
     * back to `$default` for null/array/object. The shape every `info.version` / `uir` read uses.
     */
    public static function stringOr(mixed $value, string $default): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    public static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * A mixed value coerced to a string-keyed map; non-arrays become an empty map.
     * The guarded counterpart of {@see Arr::stringKeyed()} for the raw-config bags.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        return is_array($value) ? Arr::stringKeyed($value) : [];
    }

    /**
     * A string→string map: keys coerced to strings, non-string values dropped.
     *
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $out[(string) $key] = $item;
            }
        }

        return $out;
    }

    /**
     * A string-keyed map of raw sub-maps (name → object): entries with a
     * non-string key or non-array value are dropped. Used for component schema
     * and security-scheme maps.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function mapOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * String members of a list, in order; non-strings dropped.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * A list of raw map members (non-arrays dropped), or null when the value is
     * not a list at all — the shape used for `servers`, `security`, `tags`.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function listOfMaps(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Hydrate each map member of a list through `$factory`; non-arrays dropped.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $factory
     * @return list<T>
     */
    public static function listOf(mixed $value, callable $factory): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $factory(Arr::stringKeyed($item));
            }
        }

        return $out;
    }

    /**
     * Hydrate each map member of a keyed collection through `$factory`, keys
     * coerced to strings; non-arrays dropped.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $factory
     * @return array<string, T>
     */
    public static function mapOf(mixed $value, callable $factory): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $out[(string) $key] = $factory(Arr::stringKeyed($item));
            }
        }

        return $out;
    }

    /**
     * Hydrate a single nested map through `$factory`, or null when absent/malformed.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $factory
     * @return T|null
     */
    public static function objectOrNull(mixed $value, callable $factory): mixed
    {
        return is_array($value) ? $factory(Arr::stringKeyed($value)) : null;
    }
}
