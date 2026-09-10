<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Support\Arr;
use stdClass;

/**
 * Applies the codegen-facing decoration to an enum schema: SDK member-name hints per the
 * `enums.naming` policy, and per-value descriptions in the two shapes tools consume. The one
 * implementation of these rules — core's component enums and the Laravel adapter's allow-list enums
 * both route through it, so the two can never emit different decoration standards.
 *
 * The `x-enumDescriptions` map is emitted only when every value has prose — Redoc hides values
 * missing from the map, so completeness is that extension's contract — and always as a JSON OBJECT:
 * PHP re-coerces a numeric-string key back to an int, so the map of a contiguous zero-based
 * int-backed enum is a LIST, and `["a","b"]` is not the shape the extension's consumers read. The
 * parallel `x-enum-descriptions` array is emitted whenever at least one value has prose, full length
 * with empty-string gaps, because array consumers apply it by index. Name hints are emitted only when
 * the names line up one-to-one with the values — a short array would silently rename a prefix
 * downstream.
 *
 * Every one of those shapes is POSITIONAL, so the decoration is computed against the values the
 * document will publish rather than the ones handed in: `enum` is a value list and the canonicalizer
 * holds each value once, so a repeated value left in here would shorten `enum` afterwards while the
 * parallel arrays kept their length — and each member past the repeat would take the previous one's
 * name and prose in every generated client. Deduping here is what keeps the check the names are
 * accepted on a check against the published list.
 */
final class EnumDecoration
{
    /**
     * @param  array<string, mixed>  $schema  an enum-bearing schema fragment
     * @param  string  $naming  the `enums.naming` policy keyword
     * @param  list<string>  $names  identifier-safe member names, parallel to the schema's `enum`
     * @param  array<string, string>  $descriptions  prose keyed by the stringified enum value
     * @return array<string, mixed>
     */
    public static function apply(array $schema, string $naming, array $names, array $descriptions): array
    {
        $values = $schema['enum'] ?? null;
        if (! is_array($values) || $values === []) {
            return $schema;
        }

        [$values, $names] = self::distinct(array_values($values), $names);
        $schema['enum'] = $values;

        $keys = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values);

        $texts = array_map(static fn (string $key): string => $descriptions[$key] ?? '', $keys);
        if (array_filter($texts, static fn (string $text): bool => $text !== '') !== []) {
            if (! in_array('', $texts, true)) {
                $schema['x-enumDescriptions'] = self::object(array_combine($keys, $texts));
            }

            $schema['x-enum-descriptions'] = $texts;
        }

        if ($names !== [] && count($names) === count($values)) {
            foreach (self::namingKeys($naming) as $key) {
                $schema[$key] = $names;
            }
        }

        return $schema;
    }

    /**
     * The values with each held once, and the name hints kept in step with them. Two identical values
     * are one value, so the first occurrence's name is the name of that value rather than a competing
     * claim — every caller derives its names from the values or from case names PHP already keeps
     * distinct, so there is no second answer to weigh.
     *
     * The distinctness itself is {@see Arr::distinctValues()}, which is also what the canonicalizer
     * holds `enum` to: two readings of sameness would leave the parallel arrays one value out of step
     * on exactly the input the two disagreed about.
     *
     * @param  list<mixed>  $values
     * @param  list<string>  $names
     * @return array{0: list<mixed>, 1: list<string>}
     */
    private static function distinct(array $values, array $names): array
    {
        // Whether the names line up is decided against the list as HANDED IN. Deciding it after would
        // let a name array that was never parallel to anything become the right length by accident,
        // and start renaming members it was never a claim about.
        $aligned = $names !== [] && count($names) === count($values);

        // The same reading of sameness the canonicalizer holds `enum` to, because it is the same
        // question — and the names are reindexed by the answer rather than by a second one.
        ['values' => $kept, 'indexes' => $indexes] = Arr::distinctValues($values);

        return [$kept, $aligned ? array_map(static fn (int $index): string => $names[$index], $indexes) : []];
    }

    /**
     * A description map as JSON: an array wherever its keys carry it, {@see stdClass} where they would
     * serialise as a list instead. The canonicalizer restores the same shape after a JSON round trip,
     * so a replayed fragment says what a cold build says.
     *
     * @param  array<array-key, string>  $map
     * @return array<array-key, string>|stdClass
     */
    private static function object(array $map): array|stdClass
    {
        return array_is_list($map) ? (object) $map : $map;
    }

    /**
     * The extension keys a naming keyword emits: `names` (the default) carries both spellings so
     * OpenAPI Generator, NSwag and the TS toolchain are all served; the single-key keywords remain
     * for authors pinning one tool's shape; `none` emits nothing.
     *
     * @return list<string>
     */
    private static function namingKeys(string $naming): array
    {
        return match ($naming) {
            'names' => ['x-enum-varnames', 'x-enumNames'],
            'x-enumNames', 'x-enum-varnames' => [$naming],
            default => [],
        };
    }
}
