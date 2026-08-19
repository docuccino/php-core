<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

/**
 * What stops an operationId being a generated client's method name. Uniqueness is not this table's
 * business — `route.duplicate-operation-id` owns that, and reports it where the pair is met, with
 * both route signatures to hand.
 *
 * The alphabet is deliberately wider than an identifier's: `.`, `-` and `@` are what Docuccino's own
 * `route-name` and `controller-method` strategies mint, and every generator in this space folds them
 * into a name. A rule that fired on our own default output would be the noise it exists to prevent.
 */
final class OperationIdStyle
{
    /**
     * Clause → the pattern that raises it, first match wins. Emptiness is tested before the alphabet,
     * which an empty string also fails but far less usefully. Deliberately byte-wise: a non-ASCII
     * character's bytes fall outside the class, so it needs no `u` modifier and can't trip over
     * invalid UTF-8.
     *
     * @var array<string, string>
     */
    public const PROBLEMS = [
        'is empty' => '/^\s*$/',
        'carries characters outside letters, digits and the separators . - _ @' => '/[^A-Za-z0-9._@-]/',
        'starts with a digit' => '/^[0-9]/',
    ];

    /** The clause naming why the id cannot be a method name, null when it can. */
    public static function problem(string $operationId): ?string
    {
        foreach (self::PROBLEMS as $clause => $pattern) {
            if (preg_match($pattern, $operationId) === 1) {
                return $clause;
            }
        }

        return null;
    }
}
