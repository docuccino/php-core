<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * The bytes of YAML text the reader has handed to a scan that reads it whole: a parse, the search for a mark
 * the text does not spell, and the count of the lines before each merge key. Counted for a test, which holds
 * a read to a fixed number of passes over its text however many candidates the text holds.
 *
 * @internal
 */
final class TextScans
{
    private static int $bytes = 0;

    /** $text, counted as scanned. */
    public static function of(string $text): string
    {
        self::$bytes += strlen($text);

        return $text;
    }

    public static function bytes(): int
    {
        return self::$bytes;
    }
}
