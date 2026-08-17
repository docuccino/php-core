<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Makes a string lifted out of a document safe to print. A diff is run against an artifact nobody
 * re-read first, and a name, path or version in it can carry escape sequences, carriage returns or
 * direction overrides that recolour an operator's terminal or forge a line of a CI log. Anything that
 * steers a terminal, or ends a line, rather than showing in it becomes a visible escape — an ASCII
 * control as `\xNN`, a C1 control, a line separator or a bidi formatting character as `\u{XXXX}`.
 * Everything else, non-ASCII included, is left exactly as written, because a name or a description may
 * legitimately carry it.
 *
 * Byte-wise on purpose, matching the UTF-8 encodings of the non-ASCII characters directly rather than
 * asking PCRE to decode: UTF-8 is self-synchronising, so those byte sequences cannot occur inside another
 * character, and a byte pattern has no decoding to fail at. Malformed input therefore still gets the
 * escaping it needs, and comes back as written rather than blanked or rejected.
 *
 * @internal
 */
final class PlainText
{
    /**
     * ASCII controls and DEL, the C1 controls (U+0080-U+009F), the line and paragraph separators
     * (U+2028-U+2029), and the Unicode bidirectional formatting characters (U+061C, U+200E-U+200F,
     * U+202A-U+202E, U+2066-U+2069) — the ones that reorder a line without showing in it. The separators
     * earn their place off the terminal: a terminal shows nothing for them, but a CI log rendered as a web
     * page breaks a line on either, which is the same forged verdict a bare `\n` would give.
     */
    private const string UNSAFE = '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xD8\x9C|\xE2\x80[\x8E\x8F\xA8-\xAE]|\xE2\x81[\xA6-\xA9]/';

    public static function of(string $text): string
    {
        $safe = preg_replace_callback(self::UNSAFE, self::escape(...), $text);

        // Nothing in a byte-wise, backtrack-free pattern gives PCRE anything to fail at, but a null must
        // never be cast to '': blanking the text is a silent loss where an over-escaped line is only ugly.
        return $safe ?? addcslashes($text, "\x00..\x1F\x7F..\xFF");
    }

    /**
     * @param  array<int|string, string>  $match
     */
    private static function escape(array $match): string
    {
        $bytes = $match[0];
        $length = strlen($bytes);

        if ($length === 1) {
            return sprintf('\x%02X', ord($bytes));
        }

        $codepoint = $length === 2
            ? ((ord($bytes[0]) & 0x1F) << 6) | (ord($bytes[1]) & 0x3F)
            : ((ord($bytes[0]) & 0x0F) << 12) | ((ord($bytes[1]) & 0x3F) << 6) | (ord($bytes[2]) & 0x3F);

        return sprintf('\u{%04X}', $codepoint);
    }
}
