<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Hydration helpers for the JSON boundary. Decoded UIR/OAS data is `array<mixed, mixed>`; these
 * coerce members to the narrow shapes the document model expects, dropping anything malformed.
 * Every `fromArray()` in the model uses one instead of hand-rolling the same guarded loop.
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
     * A string, coercing other scalars to their string form; `$default` for null/array/object.
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
     * A string-keyed map; non-arrays become empty. The guarded {@see Arr::stringKeyed()}.
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
     * name → raw sub-map, dropping non-string keys and non-array values. Component schema and
     * security-scheme maps use this.
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
     * Raw map members (non-arrays dropped), or null when the value isn't an array at all — the shape
     * `servers` and `tags` want. `security` is a list too and still wants {@see securityRequirements()}.
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
     * The Security Requirement Objects a `security` member states. OAS says that member IS a list of them,
     * so an artifact that wrote one requirement without the list around it is malformed — and unambiguous:
     * the string keys are scheme names, which is one requirement. Recovered rather than dropped, because a
     * reader that never sees the names reads a document demanding a scheme as one demanding nothing — and
     * the differ stands a real break down to silence on the strength of it.
     *
     * {@see listOfMaps()} cannot serve here: `servers` and `tags` recover from a bare map by UNWRAPPING it
     * (`{"prod": {"url": …}}` is one server), which for `security` throws the scheme name away.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function securityRequirements(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        $bare = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $bare[$key] = $item;
            } elseif (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        // Never as an empty requirement alongside: `[]` in the list is how a document says the API may also
        // be called with no credentials at all, which is the opposite of what a bare map states.
        if ($bare !== []) {
            $out[] = $bare;
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
     * Hydrate each map member of a keyed collection through `$factory`; keys coerced to strings.
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
