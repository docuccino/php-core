<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

/**
 * The field-path grammar the request body is written in: a `.` separates one name from the next, a
 * `*` segment stands for every element of an array, and `\.` is a dot that belongs to the name rather
 * than separating two. It is Laravel's validation-key grammar because the body is assembled from
 * validation keys — and once one reader of a body path folds an escape, every reader of a body path
 * has to, or the same string means two things depending on who read it.
 *
 * The same path has a second spelling in a query string, where a nested name rides as brackets:
 * {@see toQueryName()} writes it and {@see fromQueryName()} reads it back. Both live here so the writer
 * and the reader of that spelling cannot drift — a guard matching a declared parameter name against a
 * validation key has to recognise exactly the names the write produced, and nothing else.
 *
 * Not to be confused with the adapter's `Integrations\Support\FieldPaths`, which asks what a SET of
 * recovered rule keys says about one field's container. This is the split itself.
 */
final class FieldPath
{
    /**
     * The path's segments, escapes resolved. An empty segment — a leading, trailing or doubled `.`,
     * or an empty path — is kept rather than dropped, because it is the caller's evidence that the
     * string names no field at all.
     *
     * @return non-empty-list<string>
     */
    public static function segments(string $path): array
    {
        $segments = [];
        $current = '';
        $length = strlen($path);

        for ($i = 0; $i < $length; $i++) {
            $character = $path[$i];

            // Laravel's own escape, and read the way Laravel reads it: the backslash disappears only
            // in front of a dot, so a lone backslash stays part of the name.
            if ($character === '\\' && ($path[$i + 1] ?? '') === '.') {
                $current .= '.';
                $i++;

                continue;
            }

            if ($character === '.') {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $segments[] = $current;

        return $segments;
    }

    /** Whether every segment names something — the check a caller owes before walking the path. */
    public static function isWellFormed(string $path): bool
    {
        return ! in_array('', self::segments($path), true);
    }

    /**
     * The bracketed name a path takes in a query string: `filter.radius_lat` rides as
     * `filter[radius_lat]`. Escapes are already folded, since brackets separate segments on the wire —
     * and a segment holding a `]` has no spelling here at all, so {@see fromQueryName()} refuses what
     * this would write for one.
     *
     * @param  non-empty-list<string>  $segments
     */
    public static function toQueryName(array $segments): string
    {
        $name = array_shift($segments);

        foreach ($segments as $segment) {
            $name .= '['.$segment.']';
        }

        return $name;
    }

    /**
     * {@see toQueryName()} read backwards, or null for a name that write does not produce: an empty
     * container or member name, an unbalanced bracket, text after the last `]`. A `[` inside a group is a
     * member's own character, as the wire reads it (`?filter[a[b]=` sets `filter`'s `a[b`).
     *
     * @return non-empty-list<string>|null
     */
    public static function fromQueryName(string $name): ?array
    {
        if (preg_match('/^([^\[\]]+)((?:\[[^\]]+\])*)$/', $name, $matched) !== 1) {
            return null;
        }

        preg_match_all('/\[([^\]]+)\]/', $matched[2], $members);

        return [$matched[1], ...$members[1]];
    }

    /**
     * The same reading in the path grammar the validation keys use — what a declaration is matched
     * against. Null where {@see fromQueryName()} refuses the name, and where a segment ends in `\`
     * before another, which would read back as an escaped dot and name a different field.
     */
    public static function queryNameAsPath(string $name): ?string
    {
        $segments = self::fromQueryName($name);
        if ($segments === null) {
            return null;
        }

        foreach (array_slice($segments, 0, -1) as $segment) {
            if (str_ends_with($segment, '\\')) {
                return null;
            }
        }

        return implode('.', array_map(
            static fn (string $segment): string => str_replace('.', '\\.', $segment),
            $segments,
        ));
    }

    /**
     * Whether `$path` names `$ancestor` itself or something inside it — one path answering for another.
     * Compared segment by segment rather than with a string prefix, because `meta\.scoring` and
     * `meta.scoring` share every character and name different things.
     */
    public static function isAtOrUnder(string $path, string $ancestor): bool
    {
        $under = self::segments($ancestor);
        $of = self::segments($path);

        return count($of) >= count($under) && array_slice($of, 0, count($under)) === $under;
    }
}
