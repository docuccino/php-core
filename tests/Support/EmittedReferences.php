<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use stdClass;

/**
 * Every `$ref` an emitted document publishes, and whether it resolves inside that document. A reference
 * pointing at nothing breaks a consumer's client on load, in every OpenAPI version, and no golden sees it:
 * a byte pin compares a document against itself, and a meta-schema says only that a `$ref` is a string.
 *
 * Only in-document references (`#/…`) are checked. One naming another file is a claim about a document
 * this walk cannot see, and calling it dangling would be a guess.
 */
final class EmittedReferences
{
    /**
     * Where each in-document `$ref` stands, and what it names.
     *
     * @return array<string, string> JSON pointer of the `$ref` member => the target it holds
     */
    public static function all(mixed $node, string $pointer = ''): array
    {
        if ($node instanceof stdClass) {
            $node = get_object_vars($node);
        }

        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            $at = $pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);

            if ((string) $key === '$ref' && is_string($value) && str_starts_with($value, '#')) {
                $found[$at] = $value;

                continue;
            }

            $found = [...$found, ...self::all($value, $at)];
        }

        return $found;
    }

    /**
     * Every reference whose target the document does not define, one line each.
     *
     * @return list<string>
     */
    public static function dangling(mixed $document): array
    {
        $broken = [];

        foreach (self::all($document) as $at => $target) {
            if (! self::resolves($document, $target)) {
                $broken[] = sprintf('%s: $ref names %s, which the document does not define', $at, $target);
            }
        }

        return $broken;
    }

    /** Whether an in-document `$ref` target names a position this document holds. */
    private static function resolves(mixed $document, string $target): bool
    {
        $path = substr($target, 1);

        if ($path === '' || $path === '/') {
            return true;
        }

        if (! str_starts_with($path, '/')) {
            // A plain `#name` fragment is a 2020-12 `$anchor`, which nothing here mints.
            return false;
        }

        $node = $document;

        foreach (explode('/', substr($path, 1)) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);

            if ($node instanceof stdClass) {
                $members = get_object_vars($node);
            } elseif (is_array($node)) {
                $members = $node;
            } else {
                return false;
            }

            if (! array_key_exists($token, $members)) {
                return false;
            }

            $node = $members[$token];
        }

        return true;
    }
}
