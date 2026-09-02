<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

/**
 * One value as a consumer would type it into Postman — a query parameter, a path segment, a form field.
 * A collection carries every value as TEXT, so this is where a document's typed values stop being typed.
 *
 * The one non-obvious rule is the non-finite float. `1e400` is an ordinary number in JSON's grammar and
 * `json_decode` saturates it to INF, so a supplied document can carry one; stringifying it would send
 * `INF`, which is no number any server parses and which the canonical serializer refuses outright. It is
 * read as no value, the same as a member nothing named.
 *
 * @internal
 */
final class Value
{
    public static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value) && ! is_finite($value)) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        // A list parameter serialises comma-separated, which is what a consumer would type.
        return is_array($value) ? implode(',', array_map(self::text(...), $value)) : '';
    }
}
