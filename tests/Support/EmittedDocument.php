<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * An emitted document as an object graph — `stdClass` per map, `array` per sequence — plus the
 * comparisons that read it. The kind distinction is the whole point: it is what `json_decode` without
 * `true` preserves and what a plain `Yaml::parse()` throws away.
 *
 * `Yaml::parse()` answers a PHP array for a mapping AND a PHP array for a sequence, so `paths: {}` and
 * `paths: []` decode identically — which is why every round-trip assertion in the suite stayed green while
 * `--yaml` shipped `paths: []` for a `paths` that is an empty MAP. {@see parseYaml()} uses
 * `PARSE_OBJECT_FOR_MAP`, which answers `stdClass` for a mapping, so the two serialisations of one
 * document become comparable position by position.
 *
 * Two comparisons read that graph and they split the work deliberately: {@see differences()} answers
 * kinds, presence and scalar values, and {@see orderDifferences()} answers member ORDER, which
 * `differences()` cannot see because it walks by name and diffs key sets.
 */
final class EmittedDocument
{
    /** Emitted YAML, read back without losing the map/sequence distinction. */
    public static function parseYaml(string $yaml): mixed
    {
        return Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
    }

    /**
     * Every position where the JSON and YAML emissions of ONE document disagree, one line each. Kinds
     * first — `map` vs `sequence` is the failure that shipped — then scalar values, which catch YAML's
     * own coercions (an unquoted `y`, a bare `1.0`, a date read back as something else).
     *
     * Presence is asked with `property_exists()`/`array_key_exists()` rather than `?? null`, because
     * `null` is a value a document really emits: three `closedAt` positions in `postman-surface` are
     * genuinely null, and under `??` a member DROPPED there read as a member written null — identical
     * on both sides, reported as agreement. That is the blindness this comparison exists to remove,
     * at exactly the Schema Object and example positions the meta-schemas leave unconstrained.
     *
     * @return list<string>
     */
    public static function differences(mixed $json, mixed $yaml, string $pointer = ''): array
    {
        $at = $pointer === '' ? '/' : $pointer;

        if (self::kind($json) !== self::kind($yaml)) {
            return [sprintf('%s: json is %s, yaml is %s', $at, self::kind($json), self::kind($yaml))];
        }

        if ($json instanceof stdClass) {
            /** @var stdClass $yaml */
            $differences = [];

            foreach (get_object_vars($json) as $key => $value) {
                $at = $pointer.'/'.self::escape((string) $key);

                if (! property_exists($yaml, (string) $key)) {
                    $differences[] = sprintf('%s: json carries a member yaml does not', $at);

                    continue;
                }

                $differences = [...$differences, ...self::differences($value, $yaml->{$key}, $at)];
            }

            foreach (array_diff(array_keys(get_object_vars($yaml)), array_keys(get_object_vars($json))) as $key) {
                $differences[] = sprintf('%s: yaml carries a member json does not', $pointer.'/'.self::escape((string) $key));
            }

            return $differences;
        }

        if (is_array($json)) {
            /** @var array<array-key, mixed> $yaml */
            $differences = [];

            foreach ($json as $index => $value) {
                if (! array_key_exists($index, $yaml)) {
                    $differences[] = sprintf('%s: json carries an item yaml does not', $pointer.'/'.$index);

                    continue;
                }

                $differences = [...$differences, ...self::differences($value, $yaml[$index], $pointer.'/'.$index)];
            }

            if (count($yaml) > count($json)) {
                $differences[] = sprintf('%s: yaml has %d items, json has %d', $at, count($yaml), count($json));
            }

            return $differences;
        }

        return $json === $yaml ? [] : [sprintf('%s: json %s, yaml %s', $at, var_export($json, true), var_export($yaml, true))];
    }

    /**
     * Every position where the two serialisations write the same members in a DIFFERENT ORDER, one line
     * each.
     *
     * {@see differences()} cannot see this by construction: it walks maps member-by-NAME and then diffs
     * key SETS, so two serialisations that carry identical members in swapped order come back identical,
     * and so does the meta-schema — JSON Schema has no way to constrain member order. That makes member
     * order a second instance of the class mapping-key quoting belongs to: a difference that exists only
     * in the BYTES, invisible to any oracle that parses first.
     *
     * It matters here because determinism is a product feature. A writer that emitted members in a
     * different order from its sibling is a real defect — the two serialisations of one document stop
     * being the same document — and the three byte-locked YAML goldens cover order for three documents at
     * one version, not for the other subjects or the other two versions.
     *
     * Only order: a differing key SET is {@see differences()}'s to report, and reporting it twice would
     * make one defect read as two.
     *
     * @return list<string>
     */
    public static function orderDifferences(mixed $json, mixed $yaml, string $pointer = ''): array
    {
        if ($json instanceof stdClass && $yaml instanceof stdClass) {
            $jsonKeys = array_keys(get_object_vars($json));
            $yamlKeys = array_keys(get_object_vars($yaml));

            $found = [];

            if ($jsonKeys !== $yamlKeys && array_diff($jsonKeys, $yamlKeys) === [] && array_diff($yamlKeys, $jsonKeys) === []) {
                $found[] = sprintf(
                    '%s: json orders %s, yaml orders %s',
                    $pointer === '' ? '/' : $pointer,
                    implode(', ', $jsonKeys),
                    implode(', ', $yamlKeys),
                );
            }

            foreach (get_object_vars($json) as $key => $value) {
                if (property_exists($yaml, (string) $key)) {
                    $found = [...$found, ...self::orderDifferences($value, $yaml->{$key}, $pointer.'/'.self::escape((string) $key))];
                }
            }

            return $found;
        }

        if (is_array($json) && is_array($yaml)) {
            $found = [];

            foreach ($json as $index => $value) {
                if (array_key_exists($index, $yaml)) {
                    $found = [...$found, ...self::orderDifferences($value, $yaml[$index], $pointer.'/'.$index)];
                }
            }

            return $found;
        }

        return [];
    }

    /**
     * How many maps in $node carry two or more members — the anti-vacuity count for
     * {@see orderDifferences()}, since a map with fewer has no order to get wrong.
     */
    public static function orderedMaps(mixed $node): int
    {
        if ($node instanceof stdClass) {
            $members = get_object_vars($node);

            return (count($members) >= 2 ? 1 : 0) + array_sum(array_map(self::orderedMaps(...), array_values($members)));
        }

        if (is_array($node)) {
            return array_sum(array_map(self::orderedMaps(...), $node));
        }

        return 0;
    }

    /** How many positions a walk of $node visits — the anti-vacuity count for any of the above. */
    public static function nodes(mixed $node): int
    {
        if ($node instanceof stdClass) {
            return 1 + array_sum(array_map(self::nodes(...), array_values(get_object_vars($node))));
        }

        if (is_array($node)) {
            return 1 + array_sum(array_map(self::nodes(...), $node));
        }

        return 1;
    }

    /**
     * Where every empty MAP sits, as JSON pointers — the shape a YAML serialiser has to get right.
     *
     * @return list<string>
     */
    public static function emptyMaps(mixed $node, string $pointer = ''): array
    {
        if ($node instanceof stdClass) {
            $members = get_object_vars($node);

            if ($members === []) {
                return [$pointer === '' ? '/' : $pointer];
            }

            $found = [];
            foreach ($members as $key => $value) {
                $found = [...$found, ...self::emptyMaps($value, $pointer.'/'.self::escape((string) $key))];
            }

            return $found;
        }

        if (is_array($node)) {
            $found = [];
            foreach ($node as $index => $value) {
                $found = [...$found, ...self::emptyMaps($value, $pointer.'/'.$index)];
            }

            return $found;
        }

        return [];
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            $value instanceof stdClass => 'map',
            is_array($value) => 'sequence',
            default => get_debug_type($value),
        };
    }

    private static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
