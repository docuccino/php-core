<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * Where things are written in JSON text, as byte offsets: the pointers a {@see PointerTrie} seeks, the first
 * place text stops being JSON, and the first place it nests past a depth.
 *
 * A member is placed at the quote that opens its name and an item at its first character. Placing reads
 * text `json_decode` has already read, so it reads only structure and the names on a sought path, and steps
 * over everything else a run of bytes at a time; a name written twice is placed where it is written last,
 * which is the one `json_decode` keeps.
 *
 * @internal
 */
final class JsonPositions
{
    private const string SPACE = " \t\n\r";

    /** A number or a literal, as RFC 8259 spells them. */
    private const string SCALAR = '/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?|true|false|null/A';

    /** As much of a string as RFC 8259 allows, from its opening quote: no raw control character, no other escape. */
    private const string STRING = '/"(?:[^"\\\\\x00-\x1F]++|\\\\(?:["\\\\\/bfnrt]|u[0-9a-fA-F]{4}))*+/A';

    private int $at = 0;

    private readonly int $length;

    private function __construct(private readonly string $text, private readonly PointerTrie $sought)
    {
        $this->length = strlen($text);
    }

    /** Records in $sought where each of its pointers is written in $text, which must be JSON. */
    public static function locate(string $text, PointerTrie $sought): void
    {
        $scan = new self($text, $sought);
        $scan->space();
        if ($scan->at < $scan->length) {
            $sought->record(0, $scan->at);
            $scan->value(0);
        }
    }

    /**
     * Where $text first stops being what `json_decode` reads: the character it cannot take, or the end where
     * the text stops short. Null where it is JSON throughout.
     */
    public static function syntaxError(string $text): ?int
    {
        $length = strlen($text);
        $open = [];
        $at = self::skipSpace($text, 0);

        while (true) {
            $char = $text[$at] ?? '';
            if ($char === '{' || $char === '[') {
                $at = self::skipSpace($text, $at + 1);
                if (($text[$at] ?? '') === ($char === '{' ? '}' : ']')) {
                    $at++;
                } else {
                    $open[] = $char === '{';
                    if ($char === '{') {
                        $at = self::member($text, $at);
                        if ($at < 0) {
                            return -$at - 1;
                        }
                    }

                    continue;
                }
            } elseif ($char === '"') {
                $at = self::string($text, $at);
                if ($at < 0) {
                    return -$at - 1;
                }
            } elseif (preg_match(self::SCALAR, $text, $match, 0, $at) === 1) {
                $at += strlen($match[0]);
            } else {
                return $at;
            }

            while (true) {
                $at = self::skipSpace($text, $at);
                if ($open === []) {
                    return $at < $length ? $at : null;
                }

                $object = $open[array_key_last($open)];
                $char = $text[$at] ?? '';
                if ($char === ($object ? '}' : ']')) {
                    array_pop($open);
                    $at++;

                    continue;
                }

                if ($char !== ',') {
                    return $at;
                }

                $at = self::skipSpace($text, $at + 1);
                if ($object) {
                    $at = self::member($text, $at);
                    if ($at < 0) {
                        return -$at - 1;
                    }
                }

                continue 2;
            }
        }
    }

    /** Where $text first opens an object or a list more than $levels deep, or null where it never does. */
    public static function deeperThan(string $text, int $levels): ?int
    {
        $length = strlen($text);
        $depth = 0;
        $at = 0;
        while (($at += strcspn($text, '{}[]"', $at)) < $length) {
            $char = $text[$at];
            if ($char === '"') {
                $at = self::stringEnd($text, $at);

                continue;
            }

            if ($char === '{' || $char === '[') {
                if (++$depth > $levels) {
                    return $at;
                }
            } else {
                $depth--;
            }

            $at++;
        }

        return null;
    }

    private function value(?int $node): void
    {
        $char = $this->text[$this->at] ?? '';
        if ($node !== null && $this->sought->leadsFurther($node) && $char === '{') {
            $this->object($node);
        } elseif ($node !== null && $this->sought->leadsFurther($node) && $char === '[') {
            $this->list($node);
        } else {
            $this->skip();
        }
    }

    private function object(int $node): void
    {
        $seen = [];
        $this->at++;
        while (true) {
            $this->space();
            if (($this->text[$this->at] ?? '') !== '"') {
                $this->at++;

                return;
            }

            $name = $this->at;
            $this->at = self::stringEnd($this->text, $name);
            $spelt = substr($this->text, $name, $this->at - $name);
            $key = str_contains($spelt, '\\') ? json_decode($spelt) : substr($spelt, 1, -1);

            $this->space();
            $this->at++;
            $this->space();

            $child = is_string($key) ? $this->sought->child($node, $key) : null;
            if ($child !== null) {
                if (isset($seen[$child])) {
                    $this->sought->forget($child);
                }

                $seen[$child] = true;
                $this->sought->record($child, $name);
            }

            $this->value($child);
            $this->space();
            if (($this->text[$this->at] ?? '') !== ',') {
                $this->at++;

                return;
            }

            $this->at++;
        }
    }

    private function list(int $node): void
    {
        $this->at++;
        $this->space();
        if (($this->text[$this->at] ?? '') === ']') {
            $this->at++;

            return;
        }

        for ($index = 0; ; $index++) {
            $this->space();
            $child = $this->sought->child($node, $index);
            if ($child !== null) {
                $this->sought->record($child, $this->at);
            }

            $this->value($child);
            $this->space();
            if (($this->text[$this->at] ?? '') !== ',') {
                $this->at++;

                return;
            }

            $this->at++;
        }
    }

    /** Steps over the value at the cursor, reading no more of it than where it ends. */
    private function skip(): void
    {
        $char = $this->text[$this->at] ?? '';
        if ($char === '"') {
            $this->at = self::stringEnd($this->text, $this->at);

            return;
        }

        if ($char !== '{' && $char !== '[') {
            $this->at += max(1, strcspn($this->text, ',]}'.self::SPACE, $this->at));

            return;
        }

        $depth = 0;
        while (($this->at += strcspn($this->text, '{}[]"', $this->at)) < $this->length) {
            $char = $this->text[$this->at];
            if ($char === '"') {
                $this->at = self::stringEnd($this->text, $this->at);

                continue;
            }

            $this->at++;
            $depth += $char === '{' || $char === '[' ? 1 : -1;
            if ($depth === 0) {
                return;
            }
        }
    }

    private function space(): void
    {
        $this->at = self::skipSpace($this->text, $this->at);
    }

    private static function skipSpace(string $text, int $at): int
    {
        return $at + strspn($text, self::SPACE, $at);
    }

    /** Just past the string whose opening quote is at $quote, or the end of $text where it is never closed. */
    private static function stringEnd(string $text, int $quote): int
    {
        $at = $quote;
        while (($at = strpos($text, '"', $at + 1)) !== false) {
            $slashes = 0;
            while ($text[$at - 1 - $slashes] === '\\') {
                $slashes++;
            }

            if ($slashes % 2 === 0) {
                return $at + 1;
            }
        }

        return strlen($text);
    }

    /**
     * A member's name and colon from $at: where its value starts, or -1 - where the text stops being JSON.
     */
    private static function member(string $text, int $at): int
    {
        if (($text[$at] ?? '') !== '"') {
            return -1 - $at;
        }

        // A name that decodes to start with NUL is one no object can hold, which `json_decode` refuses.
        if (str_starts_with(substr($text, $at, 7), '"\u0000')) {
            return -1 - $at;
        }

        $end = self::string($text, $at);
        if ($end < 0) {
            return $end;
        }

        $end = self::skipSpace($text, $end);
        if (($text[$end] ?? '') !== ':') {
            return -1 - $end;
        }

        return self::skipSpace($text, $end + 1);
    }

    /**
     * Just past the string whose opening quote is at $at, or -1 - where it stops being JSON: a raw control
     * character, an escape RFC 8259 does not have, a UTF-16 surrogate without its other half, or the end.
     */
    private static function string(string $text, int $at): int
    {
        preg_match(self::STRING, $text, $match, 0, $at);
        $spelt = $match[0] ?? '"';
        $end = $at + strlen($spelt);
        if (($text[$end] ?? '') !== '"') {
            return -1 - $end;
        }

        if (preg_match('/\\\\u[dD][89a-fA-F]/', $spelt) === 1) {
            $unpaired = self::unpairedSurrogate($spelt);
            if ($unpaired !== null) {
                return -1 - ($at + $unpaired);
            }
        }

        return $end + 1;
    }

    /** Where in $spelt a `\u` escape names half a surrogate pair without the other half beside it. */
    private static function unpairedSurrogate(string $spelt): ?int
    {
        preg_match_all('/\\\\(?:u([0-9a-fA-F]{4})|.)/s', $spelt, $escapes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $high = null;
        foreach ($escapes as [[$escape, $offset]]) {
            $unit = strlen($escape) === 6 ? (int) hexdec(substr($escape, 2)) : null;
            $isLow = $unit !== null && $unit >= 0xDC00 && $unit <= 0xDFFF;
            if ($high !== null) {
                if (! $isLow || $offset !== $high + 6) {
                    return $high;
                }

                $high = null;

                continue;
            }

            if ($isLow) {
                return $offset;
            }

            if ($unit !== null && $unit >= 0xD800 && $unit <= 0xDBFF) {
                $high = $offset;
            }
        }

        return $high;
    }
}
