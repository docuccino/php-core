<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError as OpisValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Validator;

/**
 * Turns an opis validation into {@see Finding}s, each reading as one line:
 * `<data pointer> <keyword>: <message> (schema <schema pointer>)`.
 *
 * Every schema oracle reports through this, because `isValid()` failing says only "false is not true" —
 * which names neither the position in the document nor the rule it broke.
 *
 * @internal
 */
final class SchemaFindings
{
    /**
     * How many findings a validation reports. opis stops at the first error unless told otherwise, and a
     * reader fixing a document wants every place it fails, not the first one and then another run.
     */
    public const int LIMIT = 50;

    /**
     * $validator, set to collect one finding past {@see LIMIT} — the one that shows there were more, so a
     * report cut at the limit can say so rather than read as complete.
     */
    public static function collecting(Validator $validator): Validator
    {
        $validator->setMaxErrors(self::LIMIT + 1);

        return $validator;
    }

    /**
     * Every way $instance fails the schema registered at $uri. Empty means valid.
     *
     * @return list<Finding>
     */
    public static function of(Validator $validator, mixed $instance, string $uri): array
    {
        return self::fromError($validator->validate($instance, $uri)->error());
    }

    /**
     * The findings an opis validation error holds, or none for a validation that passed. Past
     * {@see LIMIT} the first ones are kept and a last finding says there were more, because a list cut
     * short without a word is a false answer to "where does this document fail?".
     *
     * @return list<Finding>
     */
    public static function fromError(?OpisValidationError $error): array
    {
        if ($error === null) {
            return [];
        }

        $formatter = new ErrorFormatter;
        $findings = [];

        // Grouped by pointer, in the order opis first meets each, so the findings at one position read
        // together; every group is the list of what the formatter below returned.
        foreach ($formatter->formatKeyed(
            $error,
            static fn (OpisValidationError $e): Finding => new Finding(
                self::root(self::pointer($e->data()->fullPath())),
                $e->keyword(),
                $formatter->formatErrorMessage($e),
                self::pointer($e->schema()->info()->path()),
            ),
            static fn (OpisValidationError $e): string => self::pointer($e->data()->fullPath()),
        ) as $group) {
            foreach ((array) $group as $finding) {
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        if (count($findings) <= self::LIMIT) {
            return $findings;
        }

        return [
            ...array_slice($findings, 0, self::LIMIT),
            new Finding('/', null, sprintf('more than %d findings; the first %1$d are shown', self::LIMIT)),
        ];
    }

    /** The document root reads as `/`, which is what a person looks for; JSON Pointer spells it `''`. */
    private static function root(string $pointer): string
    {
        return $pointer === '' ? '/' : $pointer;
    }

    /**
     * A JSON pointer a person can read. opis percent-encodes tokens on the way out, which turns the two
     * things a reader navigates by — `$defs` and a templated path segment — into `%24defs` and `%7Bid%7D`.
     *
     * @param  array<array-key, mixed>  $path
     */
    private static function pointer(array $path): string
    {
        return rawurldecode(JsonPointer::pathToString($path));
    }
}
