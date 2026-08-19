<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * CRLF and lone CR folded to LF. Every reader of authored text owes this before the text reaches the
 * document: the same markdown checked out on Windows and on Linux is the same prose, and a line
 * ending is not a code change — emitting different bytes for it breaks determinism.
 *
 * @internal
 */
final class LineEndings
{
    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
