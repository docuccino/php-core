<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Reads a PHP (PCRE) expression as a JSON Schema (ECMA-262) `pattern` that accepts everything PCRE does
 * and compiles alike with or without ECMA-262's `u` flag — exact where it can be, wider where it cannot —
 * or refuses. The caller says how its engine reads the expression: `$unicode` for PHP's `u` (class escapes
 * then match every script), `$anchors` for whether `^`/`$` anchor the value, `$endOnly` for PHP's `D`
 * (without it PCRE's `$` also matches before a final `\n`, which ECMA-262's never does); compiling it is
 * the caller's.
 */
final class PortablePattern
{
    /** The characters an escape keeps escaped: ECMA-262 allows exactly these as a `u`-mode identity escape. */
    private const string SYNTAX = '^$\\.*+?()[]{}|/';

    /** Any non-ASCII character: one code point with `u`, one UTF-16 unit without. */
    private const string NON_ASCII = '[^\\x00-\\x7F]';

    /** The ASCII half of the Unicode-wide class escapes. */
    private const array ASCII_CLASS = ['d' => '0-9', 'w' => '0-9A-Za-z_'];

    /**
     * The pattern matching what the expression matches anywhere in a value, as PCRE's own search does.
     */
    public static function translate(string $expression, bool $unicode, bool $anchors, bool $endOnly = false): ?string
    {
        return self::read($expression, $unicode, $anchors, $endOnly)[0] ?? null;
    }

    /**
     * The pattern matching a value the expression matches whole — `^…$`, grouped when it alternates at
     * its top level so the anchors bind to every branch.
     */
    public static function anchored(string $expression, bool $unicode, bool $anchors): ?string
    {
        $read = self::read($expression, $unicode, $anchors, true);
        if ($read === null) {
            return null;
        }

        return $read[1] ? '^(?:'.$read[0].')$' : '^'.$read[0].'$';
    }

    /**
     * The values an expression matches when it is nothing but an alternation of literal strings
     * (`draft|published`, an escaped `a\.b` read as `a.b`), in source order and each once; null when any
     * branch is empty or holds a character that matches more than itself — an enum of `a.b` would be
     * narrower than the expression, which also matches `axb`.
     *
     * @return list<string>|null
     */
    public static function literals(string $expression): ?array
    {
        $values = [];
        foreach (explode('|', $expression) as $branch) {
            // Printable ASCII but the syntax characters, or escaped punctuation, which PCRE reads as itself.
            if (preg_match('/\A(?:[\x20-\x23\x25-\x27\x2C\x2D\x2F-\x3E\x40-\x5A\x5F-\x7A\x7E]|\\\\[\x20-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E])+\z/', $branch) !== 1) {
                return null;
            }
            $values[] = (string) preg_replace('/\\\\(.)/', '$1', $branch);
        }

        return array_values(array_unique($values));
    }

    /**
     * The translation, and whether it alternates at its top level; null outside the subset.
     *
     * A WIDE atom — one PCRE lets match a non-ASCII character — counts bytes or code points in PCRE and
     * UTF-16 units in ECMA-262, so it must carry `*`, `+`, `{0,}` or `{1,}`, where every count agrees.
     * Without `u` PCRE matches bytes and two wide atoms could share one character between them, so only
     * one is allowed, outside any group.
     *
     * A `$` read without `D` is `\n?$`, which is exact only where nothing after it could take that `\n`,
     * so until its top-level branch ends only `)`, a quantifier on it, `|` and another `$` may follow.
     *
     * @return array{string, bool}|null
     */
    private static function read(string $expression, bool $unicode, bool $anchors, bool $endOnly): ?array
    {
        $length = strlen($expression);
        if ($length === 0) {
            return null;
        }

        $out = '';
        $depth = 0;
        $alternates = false;
        $wideAtoms = 0;
        // What precedes: null when nothing may take a quantifier (the start, `(`, `|`, an assertion, another
        // quantifier), false for an atom or a closed group, true for a wide atom owed an unbounded one.
        $atom = null;
        // Whether a `$` read as `\n?$` still closes the current top-level branch.
        $closing = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            if (! self::printable($char)) {
                return null;
            }

            if ($closing && ! str_contains(')|$*+?{', $char)) {
                return null;
            }

            if ($char === '*' || $char === '+' || $char === '?' || $char === '{') {
                $quantifier = $atom === null ? null : self::quantifier($expression, $i, $atom);
                if ($quantifier === null) {
                    return null;
                }
                $out .= $quantifier;
                $i += strlen($quantifier) - 1;
                $atom = null;

                continue;
            }

            if ($atom === true) {
                return null;
            }

            switch ($char) {
                case '\\':
                    $escape = self::escape($expression[++$i] ?? '', $unicode, $anchors);
                    if ($escape === null) {
                        return null;
                    }
                    [$text, $atom] = $escape;
                    $out .= $text;
                    break;
                case '[':
                    $class = self::readClass($expression, $i, $unicode);
                    if ($class === null) {
                        return null;
                    }
                    [$i, $text, $atom] = $class;
                    $out .= $text;
                    break;
                case '(':
                    if (($expression[$i + 1] ?? '') === '?') {
                        if (($expression[$i + 2] ?? '') !== ':') {
                            return null;
                        }
                        $i += 2;
                        $out .= '(?:';
                    } else {
                        $out .= '(';
                    }
                    $depth++;
                    $atom = null;
                    break;
                case ')':
                    if (--$depth < 0) {
                        return null;
                    }
                    $out .= ')';
                    $atom = false;
                    break;
                case '|':
                    $alternates = $alternates || $depth === 0;
                    $closing = $closing && $depth > 0;
                    $out .= '|';
                    $atom = null;
                    break;
                case '^':
                case '$':
                    if (! $anchors) {
                        return null;
                    }
                    if ($char === '$' && ! $endOnly) {
                        $out .= '\\n?$';
                        $closing = true;
                    } else {
                        $out .= $char;
                    }
                    $atom = null;
                    break;
                    // `.` stops at `\n` alone in PCRE and at `\r`, U+2028 and U+2029 too in ECMA-262; a
                    // stray `]` or `}` is a PCRE literal and a `u`-mode ECMA-262 error.
                case '.':
                case ']':
                case '}':
                    return null;
                default:
                    $out .= $char;
                    $atom = false;
            }

            if ($atom === true && ! $unicode && ($depth > 0 || ++$wideAtoms > 1)) {
                return null;
            }
        }

        return $depth === 0 && $atom !== true ? [$out, $alternates] : null;
    }

    /**
     * An escape outside a class: its translation, and what it is as an atom — false for a plain one, true
     * for a wide one, null for an assertion — or null when the dialects part on it.
     *
     * @return array{string, ?bool}|null
     */
    private static function escape(string $char, bool $unicode, bool $anchors): ?array
    {
        if ($char === '' || ! self::printable($char)) {
            return null;
        }

        if (str_contains(self::SYNTAX, $char)) {
            return ['\\'.$char, false];
        }

        // PCRE reads any other escaped punctuation as itself, as ECMA-262 reads it bare outside a class.
        if (! ctype_alnum($char)) {
            return [$char, false];
        }

        // Under `u` a class escape matches every script: the ASCII class or any non-ASCII character is
        // wider and true. Without it PCRE's are ASCII, as ECMA-262's are — whose `\s` takes Unicode spaces
        // too, which is wider and true.
        return match ($char) {
            'd', 'w' => $unicode ? ['(?:['.self::ASCII_CLASS[$char].']|'.self::NON_ASCII.')', true] : ['\\'.$char, false],
            'D', 'W' => [$unicode ? '[^'.self::ASCII_CLASS[strtolower($char)].']' : '\\'.$char, true],
            's' => $unicode ? null : ['\\s', false],
            // `\B` without `u` also holds between two bytes of one character, which ECMA-262 never sees.
            'b' => $unicode ? null : ['\\b', null],
            'A' => $anchors ? ['^', null] : null,
            'z' => $anchors ? ['$', null] : null,
            default => null,
        };
    }

    /**
     * The class opened at `$open`: the offset of its `]`, its translation, and whether it is wide — or null
     * when it is not portable: a `]` first (a PCRE literal, an empty class to ECMA-262), an unescaped `[`
     * (PCRE's POSIX classes), a range backwards or reaching a class escape, or an escape the dialects part on.
     *
     * @return array{int, string, bool}|null
     */
    private static function readClass(string $expression, int $open, bool $unicode): ?array
    {
        $length = strlen($expression);
        $i = $open + 1;
        $negated = ($expression[$i] ?? '') === '^';
        if ($negated) {
            $i++;
        }

        $body = '';
        $widened = false;
        // The previous member when it can start a range, whether a `-` is pending, and whether the previous
        // member was a class escape — which no `-` may follow into a range.
        $previous = null;
        $range = false;
        $afterClassEscape = false;

        for ($first = true; $i < $length; $i++, $first = false) {
            $char = $expression[$i];
            if (! self::printable($char) || $char === '[') {
                return null;
            }

            if ($char === ']') {
                if ($first) {
                    return null;
                }

                return $widened
                    ? [$i, '(?:['.$body.']|'.self::NON_ASCII.')', true]
                    : [$i, ($negated ? '[^' : '[').$body.']', $negated];
            }

            if ($char === '-' && ! $range && ($expression[$i + 1] ?? ']') !== ']') {
                if ($afterClassEscape) {
                    return null;
                }
                if ($previous !== null) {
                    $range = true;
                    $body .= '-';

                    continue;
                }
            }
            $afterClassEscape = false;

            $text = $char;
            if ($char === '\\') {
                $escaped = $expression[++$i] ?? '';
                if ($escaped === '' || ! self::printable($escaped)) {
                    return null;
                }

                if (isset(self::ASCII_CLASS[$escaped]) || $escaped === 's') {
                    // Negated, a Unicode-wide escape would negate its non-ASCII half too; and ECMA-262's `\s`
                    // is wider than PCRE's, so `[^\s]` would refuse spaces PCRE accepts.
                    if ($range || ($negated && ($unicode || $escaped === 's')) || ($unicode && $escaped === 's')) {
                        return null;
                    }
                    $body .= $unicode ? self::ASCII_CLASS[$escaped] : '\\'.$escaped;
                    $widened = $widened || $unicode;
                    $previous = null;
                    $afterClassEscape = true;

                    continue;
                }

                if (ctype_alnum($escaped)) {
                    return null;
                }

                $char = $escaped;
                $text = str_contains(self::SYNTAX.'-', $escaped) ? '\\'.$escaped : $escaped;
            }

            $body .= $text;

            if ($range) {
                if ($previous === null || ord($char) < ord($previous)) {
                    return null;
                }
                $range = false;
                $previous = null;

                continue;
            }

            $previous = $char;
        }

        return null;
    }

    /**
     * The quantifier starting at `$i`, a lazy `?` included, or null when it is malformed, possessive, or —
     * on a wide atom — bounded above, or below by more than one.
     */
    private static function quantifier(string $expression, int $i, bool $wide): ?string
    {
        if ($expression[$i] === '{') {
            if (preg_match('/\G\{(\d+)(?:(,)(\d*))?}/', $expression, $range, 0, $i) !== 1) {
                return null;
            }
            $upper = $range[3] ?? '';
            if ($upper !== '' && (int) $upper < (int) $range[1]) {
                return null;
            }
            if ($wide && (($range[2] ?? '') === '' || $upper !== '' || (int) $range[1] > 1)) {
                return null;
            }
            $quantifier = $range[0];
        } else {
            if ($wide && $expression[$i] === '?') {
                return null;
            }
            $quantifier = $expression[$i];
        }

        return match ($expression[$i + strlen($quantifier)] ?? '') {
            '?' => $quantifier.'?',
            '+' => null,
            default => $quantifier,
        };
    }

    private static function printable(string $char): bool
    {
        $code = ord($char);

        return $code >= 0x20 && $code <= 0x7E;
    }
}
