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
}
