<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Validator;

/**
 * Turns an opis validation into readable findings, one line each:
 * `<data pointer> <keyword>: <message> (schema <schema pointer>)`.
 *
 * Every schema oracle in the suite reports through this, because `isValid()` failing an assertion says
 * only "false is not true" — which names neither the position in the document nor the rule it broke.
 */
final class SchemaFindings
{
    /**
     * Every way $instance fails the schema registered at $uri. Empty means valid.
     *
     * @return list<string>
     */
    public static function of(Validator $validator, mixed $instance, string $uri): array
    {
        $error = $validator->validate($instance, $uri)->error();

        if ($error === null) {
            return [];
        }

        $findings = [];

        foreach ((new ErrorFormatter)->formatKeyed(
            $error,
            static fn (ValidationError $e): string => sprintf(
                '%s: %s (schema %s)',
                $e->keyword(),
                (new ErrorFormatter)->formatErrorMessage($e),
                self::pointer($e->schema()->info()->path()),
            ),
            static fn (ValidationError $e): string => self::pointer($e->data()->fullPath()),
        ) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $findings[] = ($pointer === '' ? '/' : $pointer).' '.$message;
            }
        }

        return $findings;
    }

    /**
     * A JSON pointer a person can read. opis percent-encodes tokens on the way out, which turns the two
     * things a reader navigates by — `$defs` and a templated path segment — into `%24defs` and `%7Bid%7D`.
     *
     * @param  list<int|string>  $path
     */
    private static function pointer(array $path): string
    {
        return rawurldecode(JsonPointer::pathToString($path));
    }
}
