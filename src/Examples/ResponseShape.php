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
 * @internal
 */
final class ResponseShape
{
    /** How deep the walk goes; a recorded body is a response, not a document. */
    private const int MAX_DEPTH = 64;

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

            $members = [];
            foreach ($value as $key => $item) {
                $members[] = (string) $key.':'.self::describe($item, $depth + 1);
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
