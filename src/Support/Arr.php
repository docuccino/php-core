<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Small array helpers for the JSON boundary, where decoded data is typed
 * `array<mixed, mixed>` but object members are always string-keyed.
 *
 * @internal
 */
final class Arr
{
    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * The sorted, deduplicated union of two arrays' values, each coerced to a
     * string. Used to walk both sides of a diff in a stable order.
     *
     * @param  list<int|string>  $a
     * @param  list<int|string>  $b
     * @return list<string>
     */
    public static function sortedUnion(array $a, array $b): array
    {
        $values = array_map(static fn (int|string $v): string => (string) $v, [...$a, ...$b]);
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * The value at a concrete key-path, or null when any segment is missing or a
     * non-array is encountered mid-walk.
     *
     * @param  array<array-key, mixed>  $document
     * @param  list<int|string>  $path
     */
    public static function valueAt(array $document, array $path): mixed
    {
        $node = $document;

        foreach ($path as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }
}
