<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use stdClass;

/**
 * The members of a decoded JSON object, for walks over the OPTIONAL positions a document may not carry.
 *
 * A member name is a string, but PHP turns a numeric one into an int on the way into an array — a
 * `responses` map is keyed by status code — so the keys are typed as they arrive and a caller needing
 * the name casts it back.
 *
 * @internal
 */
final class ObjectMembers
{
    /**
     * The members of $value where it is an object, and none where it is anything else.
     *
     * @return array<array-key, mixed>
     */
    public static function of(mixed $value): array
    {
        return $value instanceof stdClass ? get_object_vars($value) : [];
    }

    /**
     * The member NAMES of $value, as the strings they are in the document.
     *
     * @return list<string>
     */
    public static function names(mixed $value): array
    {
        return array_map(strval(...), array_keys(self::of($value)));
    }
}
