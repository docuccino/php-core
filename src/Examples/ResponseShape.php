<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use stdClass;

/**
 * The structure of a recorded body with every value thrown away: which members exist, and what kind
 * of thing each one holds.
 *
 * This is what makes a recording safe to re-record. A timestamp, a UUID or an autoincrement key
 * changes the body on every run and the shape on none of them, so {@see ExampleRecording} can leave a
 * committed body alone unless the structure actually moved — which is a contract change, and worth
 * seeing in the diff.
 *
 * That promise covers a generated id wherever it sits, and an id-keyed map puts one in the KEY: a map
 * under `0193a1f0-…` holds one kind of thing, it does not have a member of that name. Such a key
 * therefore contributes its KIND, while how many of them there are stays in the fingerprint — so a key
 * that changes kind and a map that grows a member both still move it.
 *
 * @internal
 */
final class ResponseShape
{
    /** How deep the walk goes; a recorded body is a response, not a document. */
    private const int MAX_DEPTH = 64;

    /**
     * Key text an application MINTS rather than names, as kind marker => pattern. ULID is here beside
     * UUID because a framework mints it the same way, not on the chance one might; both are fixed-width
     * and unambiguous, which is what lets a key be read with no schema to ask.
     *
     * @var array<string, string>
     */
    private const array GENERATED_KEY_KINDS = [
        '<uuid>' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
        '<ulid>' => '/^[0-7][0-9ABCDEFGHJKMNPQRSTVWXYZ]{25}$/iD',
    ];

    /** A short, stable fingerprint of the body's structure. */
    public static function of(mixed $body): string
    {
        return substr(hash('sha256', self::describe($body, 0)), 0, 16);
    }

    /**
     * How many distinct places in the body carry a value at all. List members collapse onto one path,
     * so a hundred-row page counts the same as a one-row page — the question is how much of the shape
     * an example actually illustrates, never how much of it there is.
     */
    public static function populatedPaths(mixed $body): int
    {
        $paths = [];
        self::collect($body, '', $paths, 0);

        return count($paths);
    }

    /**
     * Every kind marker a generated key can contribute, so a guard reads the set rather than a second
     * copy of it. {@see GENERATED_KEY_KINDS}
     *
     * @return list<string>
     */
    public static function generatedKeyKinds(): array
    {
        return array_keys(self::GENERATED_KEY_KINDS);
    }

    private static function describe(mixed $value, int $depth): string
    {
        // An object stays an object even when its members happen to be `0`, `1`, `2` — the map branch
        // is chosen by what the value IS, never by what its keys look like.
        $map = $value instanceof stdClass;
        if ($map) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '…';
            }

            if (! $map && array_is_list($value)) {
                // Members are deduplicated and sorted, so two pages holding the same kind of row in a
                // different order are one shape.
                $members = [];
                foreach ($value as $item) {
                    $members[self::describe($item, $depth + 1)] = true;
                }
                $shapes = array_keys($members);
                sort($shapes);

                return '['.implode('|', $shapes).']';
            }

            // Members are LISTED, not deduplicated, so nine ids stay nine.
            $members = [];
            foreach ($value as $key => $item) {
                $members[] = self::keyKind((string) $key).':'.self::describe($item, $depth + 1);
            }
            sort($members);

            return '{'.implode(',', $members).'}';
        }

        return match (true) {
            $value === null => 'n',
            is_bool($value) => 'b',
            is_int($value) => 'i',
            is_float($value) => 'f',
            is_string($value) => 's',
            default => '?',
        };
    }

    /** The kind marker of a key that was generated, or the key itself when someone chose it. */
    private static function keyKind(string $key): string
    {
        foreach (self::GENERATED_KEY_KINDS as $kind => $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return $kind;
            }
        }

        return $key;
    }

    /**
     * @param  array<string, true>  $paths
     */
    private static function collect(mixed $value, string $path, array &$paths, int $depth): void
    {
        $map = $value instanceof stdClass;
        if ($map) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return;
            }

            // Key text reaches a path as it is; only describe() reads a generated key as its kind, so
            // an id-keyed map still counts each id as a place of its own.
            $list = ! $map && array_is_list($value);
            foreach ($value as $key => $item) {
                self::collect($item, $path.'/'.($list ? '*' : (string) $key), $paths, $depth + 1);
            }

            return;
        }

        if ($value !== null) {
            $paths[$path] = true;
        }
    }
}
