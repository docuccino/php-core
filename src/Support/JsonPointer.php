<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * RFC 6901 JSON Pointers, which the emitters use to tell a reader WHERE a member was dropped. Both
 * escapes matter here: a path template carries `/` and a media type never does, so a pointer built by
 * concatenation alone names the wrong node for every path in the document.
 *
 * @internal
 */
final class JsonPointer
{
    /** A child pointer, with the RFC 6901 escapes the token needs. */
    public static function child(string $parent, string $token): string
    {
        return $parent.'/'.str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
