<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * The plain scalars symfony/yaml reads as a value no YAML version gives them, marked in the text so the parser
 * reads each as the string it is: an impossible timestamp (`2024-02-30`, which PHP's calendar moves into
 * March), a timestamp as a mapping key (a count of seconds), `true`, `false`, `null`, `.inf` and `.nan` in a
 * case YAML does not spell them (`tRUE`), and whatever the non-specific tag is on (`! 12`, a string to YAML).
 * The mark is a character the text cannot spell, put before each and taken back out of every string and name
 * the parser hands back ({@see unmarked()}).
 *
 * @internal
 */
final class PlainScalars
{
    /**
     * A non-specific tag before a plain scalar; or a timestamp as symfony/yaml matches one, or a keyword in any
     * case, starting where a plain scalar can and ending where one does: at a line break, a comment, a flow
     * indicator, or the colon of a key.
     */
    private const string CANDIDATE = '/(?<![^\s\x00\[{,])(?:(?<tag>![ \t]+)(?=[^\s|>"\'\[\]{},&*#!%@`])|(?:(?<year>\d{4})-(?<month>\d\d?)-(?<day>\d\d?)(?:(?:[Tt]|[ \t]+)(?<hour>\d\d?):(?<minute>\d\d):(?<second>\d\d)(?:\.\d*)?(?:[ \t]*(?<zone>Z|(?<sign>[-+])(?<zoneHour>\d\d?)(?::(?<zoneMinute>\d\d))?))?)?|(?<keyword>-?\.(?i:inf)|\.(?i:nan)|(?i:true|false|null)))(?=[ \t\x00\x0B]*(?<end>:(?:[ \t\r\n]|\z)|[\r\n#,\]}]|\z)))/';

    /** The spellings of those keywords that YAML reads as one. */
    private const array KEYWORDS = ['true', 'True', 'TRUE', 'false', 'False', 'FALSE', 'null', 'Null', 'NULL', '.inf', '.Inf', '.INF', '-.inf', '-.Inf', '-.INF', '.nan', '.NaN', '.NAN'];

    /**
     * $text with a mark before every plain scalar symfony/yaml would misread, and the mark: null where there
     * is none to make.
     *
     * @return array{string, ?string}
     *
     * @throws UnreadableDocumentException where the text spells every character a mark could be
     */
    public static function marked(string $text): array
    {
        // Matched one at a time, so a document of a hundred thousand candidates holds one match, not all of them.
        $mark = null;
        $marked = '';
        $copied = 0;
        $offset = 0;
        while (preg_match(self::CANDIDATE, $text, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            [$candidate, $at] = $match[0];
            $offset = $at + strlen((string) $candidate);
            if (! self::misread($match)) {
                continue;
            }

            // A tag keeps its place, and the mark goes on what it tags.
            $before = $match['tag'][0] === null ? $at : $offset;
            $mark ??= self::unusedCharacter($text);
            $marked .= substr($text, $copied, $before - $copied).$mark;
            $copied = $before;
        }

        return $mark === null ? [$text, null] : [$marked.substr($text, $copied), $mark];
    }

    /** $value with every $mark taken out. */
    public static function unmarked(string $value, ?string $mark): string
    {
        return $mark === null ? $value : str_replace($mark, '', $value);
    }

    /**
     * A private-use character $text neither holds nor spells as an escape, so one put into it can be told from
     * everything the document says.
     *
     * @throws UnreadableDocumentException where it spells every one
     */
    public static function unusedCharacter(string $text): string
    {
        $used = [];
        preg_match_all('/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}]|\\\\(?:u|U0000)([eEfF][0-9a-fA-F]{3})|\\\\U000([fF][0-9a-fA-F]{4})/u', $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $match) {
            $used[$match[1] !== null || $match[2] !== null ? (int) hexdec((string) ($match[1] ?? $match[2])) : mb_ord($match[0], 'UTF-8')] = true;
        }

        foreach ([[0xE000, 0xF8FF], [0xF0000, 0xFFFFD]] as [$first, $last]) {
            for ($point = $first; $point <= $last; $point++) {
                if (! isset($used[$point])) {
                    return (string) mb_chr($point, 'UTF-8');
                }
            }
        }

        throw new UnreadableDocumentException(ReadFailure::Syntax, 'The document spells every private-use character, one of which reading it needs.');
    }

    /** @param array<int|string, array{?string, int}> $match */
    private static function misread(array $match): bool
    {
        if ($match['tag'][0] !== null) {
            return true;
        }

        $keyword = $match['keyword'][0] ?? null;
        if ($keyword !== null) {
            return ! in_array($keyword, self::KEYWORDS, true);
        }

        // A key is read with no flags at all, so even a possible date becomes an integer count of seconds.
        if (str_starts_with((string) $match['end'][0], ':')) {
            return true;
        }

        return ! self::possible($match);
    }

    /**
     * Whether PHP's calendar reads the timestamp as written: `2024-02-30` it moves to 1 March, `24:00:00` to the
     * next day and a leap second to the next minute, where an impossible month or minute it refuses.
     *
     * @param  array<int|string, array{?string, int}>  $match
     */
    private static function possible(array $match): bool
    {
        $written = trim((string) $match[0][0]);

        try {
            $read = new DateTimeImmutable($written, new DateTimeZone('UTC'));
        } catch (Exception) {
            return false;
        }

        $components = ['Y' => 'year', 'n' => 'month', 'j' => 'day', 'G' => 'hour', 'i' => 'minute', 's' => 'second'];
        foreach ($components as $format => $name) {
            if ($match[$name][0] !== null && (int) $read->format($format) !== (int) $match[$name][0]) {
                return false;
            }
        }

        $zone = $match['zone'][0];
        if ($zone === null) {
            return true;
        }

        $offset = $zone === 'Z' ? 0 : ((int) $match['zoneHour'][0] * 3600 + (int) ($match['zoneMinute'][0] ?? 0) * 60) * ($match['sign'][0] === '-' ? -1 : 1);

        return $read->getOffset() === $offset;
    }
}
