<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Small array helpers for the JSON boundary, where decoded data is `array<mixed, mixed>` but object
 * members are always string-keyed.
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
     * Sorted, deduped union of two arrays' values as strings — how a diff walks both sides stably.
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
     * The values with each held once, and the source index each kept value came from — so a caller
     * holding an array PARALLEL to the values (SDK member names, per-value prose) reindexes it by the
     * same answer rather than deciding distinctness a second time and hoping the two agree.
     *
     * Identity is the value's JSON bytes: `1` and `"1"` are two values, and two arrays carrying the
     * same members in the same order are one. A value `json_encode` refuses has no bytes, so it shares
     * one key with every other such value and the first of them stands for all — vague and honest,
     * where the alternative is a key that is not a function of the value at all.
     *
     * @param  list<mixed>  $values
     * @return array{values: list<mixed>, indexes: list<int>}
     */
    public static function distinctValues(array $values): array
    {
        $kept = [];
        $indexes = [];
        $seen = [];

        foreach ($values as $index => $value) {
            $key = json_encode($value);
            $key = is_string($key) ? $key : '';

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $value;
            $indexes[] = $index;
        }

        return ['values' => $kept, 'indexes' => $indexes];
    }

    /**
     * The value at a key-path; null if a segment is missing or the walk hits a non-array.
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
