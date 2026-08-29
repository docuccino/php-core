<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The product's one wildcard grammar: `*` stands for any run of characters, including none and
 * including `/`, and everything else is literal. It is the grammar `routes.include`/`routes.exclude`
 * have always spoken — Laravel's `Str::is` — stated here so core can read it too, and so a second
 * package never has to invent a near-miss of it.
 *
 * Deliberately not `fnmatch()`: that one stops a `*` at a `/` on some platforms and honours `?` and
 * `[…]` besides, so a pattern written for one reader would mean something else in the other.
 *
 * @internal
 */
final class Glob
{
    public static function matches(string $pattern, string $subject): bool
    {
        // Settled before the expression, so a subject the `u` modifier would refuse — a name carrying
        // invalid UTF-8 — still matches what it plainly matches. It is also why this reader and an
        // exact-match one answer the same thing everywhere a `*` is absent.
        if ($pattern === '*' || $pattern === $subject) {
            return true;
        }

        $expression = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return preg_match('#^'.$expression.'\z#su', $subject) === 1;
    }

    /**
     * @param  list<string>  $patterns
     */
    public static function matchesAny(array $patterns, string $subject): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }
}
