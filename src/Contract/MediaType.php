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

    /** Whether a body of this type is JSON — `application/problem+json` and friends included. */
    public static function isJson(string $mediaType): bool
    {
        return $mediaType === 'application/json'
            || $mediaType === 'text/json'
            || str_ends_with($mediaType, '+json');
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
