<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * Media-type arithmetic for picking the `content` entry an exchange actually used.
 *
 * @internal
 */
final class MediaType
{
    /** `application/json; charset=utf-8` → `application/json`. */
    public static function base(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $base = strtolower(trim(explode(';', $header, 2)[0]));

        return $base === '' ? null : $base;
    }

    /**
     * Whether a body of this type is JSON — `application/problem+json` and friends included.
     *
     * Asked of a key {@see select()} matched case-insensitively and returned in the document's own
     * case, so it reads the same grammar: a `content` entry spelled `Application/JSON` is the JSON
     * entry it looks like, and reading it as anything else leaves a body unchecked in silence.
     */
    public static function isJson(string $mediaType): bool
    {
        $base = strtolower($mediaType);

        return $base === 'application/json' || $base === 'text/json' || str_ends_with($base, '+json');
    }

    /**
     * Whether a body of this type is a FORM: named fields the framework parses out of the message,
     * rather than bytes a decoder reads ({@see Exchange} says why that matters). Case-insensitive for
     * the reason {@see isJson()} gives.
     */
    public static function isForm(string $mediaType): bool
    {
        $base = strtolower($mediaType);

        return $base === 'application/x-www-form-urlencoded' || $base === 'multipart/form-data';
    }

    /**
     * The `content` key that describes a body of $requested: the exact type, then the type's wildcard,
     * then `*​/*`. A request or response that declared no type matches a single documented entry (there
     * is nothing to choose between) and nothing when there are several.
     *
     * @param  array<string, mixed>  $content
     */
    public static function select(array $content, ?string $requested): ?string
    {
        if ($requested === null) {
            return count($content) === 1 ? (string) array_key_first($content) : null;
        }

        foreach ([$requested, explode('/', $requested)[0].'/*', '*/*'] as $candidate) {
            foreach ($content as $key => $_) {
                if (strtolower((string) $key) === $candidate) {
                    return (string) $key;
                }
            }
        }

        return null;
    }
}
