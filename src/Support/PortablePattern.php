<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Reads a PHP (PCRE) expression as a JSON Schema (ECMA-262) `pattern` that accepts everything PCRE does
 * and compiles alike with or without ECMA-262's `u` flag — exact where it can be, wider where it cannot —
 * or refuses. The caller says how its engine reads the expression: `$unicode` for PHP's `u` (class escapes
 * then match every script), `$anchors` for whether `^`/`$` anchor the value, `$endOnly` for PHP's `D`
 * (without it PCRE's `$` also matches before a final `\n`, which ECMA-262's never does), `$caseless` for
 * PHP's `i` (each letter then spelled as its case partners); compiling it is the caller's.
 *
 * A WIDENED atom — `\d`, `\w`, `\s`, `\D`, `\W` or a property under `u` — takes every non-ASCII character
 * where PCRE's takes some, so a consumer's backtracking engine can meet ambiguity on a value the server
 * refuses at its first such character. So an expression holding one carries no other unbounded quantifier
 * and repeats no group around one, which leaves any engine one way to take a run of such characters.
 */
final class PortablePattern
{
    /** The characters an escape keeps escaped: ECMA-262 allows exactly these as a `u`-mode identity escape. */
    private const string SYNTAX = '^$\\.*+?()[]{}|/';

    /** Any non-ASCII character: one code point with `u`, one UTF-16 unit without. */
    private const string NON_ASCII = '[^\\x00-\\x7F]';

    /** The ASCII half of the Unicode-wide class escapes. */
    private const array ASCII_CLASS = ['d' => '0-9', 'w' => '0-9A-Za-z_', 's' => '\\t-\\r '];

    /**
     * The non-ASCII characters PCRE's `iu` folds onto an ASCII letter: the Kelvin sign and the long s, written
     * as themselves — `\u212A` is no escape to a validator that compiles the pattern as PCRE.
     */
    private const array CASE_PARTNERS = ['k' => "\u{212A}", 's' => "\u{17F}"];

    /**
     * The pattern matching what the expression matches anywhere in a value, as PCRE's own search does.
     */
    public static function translate(string $expression, bool $unicode, bool $anchors, bool $endOnly = false, bool $caseless = false): ?string
    {
        return self::read($expression, $unicode, $anchors, $endOnly, $caseless)[0] ?? null;
    }

    /**
     * The pattern matching a value the expression matches whole — `^…$`, grouped when it alternates at
     * its top level so the anchors bind to every branch.
     */
    public static function anchored(string $expression, bool $unicode, bool $anchors): ?string
    {
        $read = self::read($expression, $unicode, $anchors, true, false);
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
    private static function read(string $expression, bool $unicode, bool $anchors, bool $endOnly, bool $caseless): ?array
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
        // Whether a widened atom was read, how many unbounded quantifiers, and whether a repeat encloses one;
        // per open group, whether it holds an unbounded quantifier, and `$closed` the group just closed.
        $widened = false;
        $unbounded = 0;
        $nested = false;
        $groups = [false];
        $closed = null;

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
                if ($closed === true && self::repeats($quantifier)) {
                    $nested = true;
                }
                if (preg_match('/\A(?:[*+]|\{\d+,})/', $quantifier) === 1) {
                    $unbounded++;
                    $groups[$depth] = true;
                }
                $closed = null;
                $out .= $quantifier;
                $i += strlen($quantifier) - 1;
                $atom = null;

                continue;
            }

            if ($atom === true) {
                return null;
            }

            $closed = null;

            switch ($char) {
                case '\\':
                    $property = self::property($expression, $i + 1, $unicode, $caseless);
                    if ($property !== null) {
                        [$i, $ascii] = $property;
                        $out .= $ascii === '' ? self::NON_ASCII : '(?:['.$ascii.']|'.self::NON_ASCII.')';
                        $atom = true;
                        $widened = true;
                        break;
                    }
                    $escaped = $expression[++$i] ?? '';
                    $escape = self::escape($escaped, $unicode, $anchors);
                    if ($escape === null) {
                        return null;
                    }
                    [$text, $atom] = $escape;
                    $out .= $text;
                    $widened = $widened || ($unicode && str_contains('dswDW', $escaped));
                    break;
                case '[':
                    $class = self::readClass($expression, $i, $unicode, $caseless);
                    if ($class === null) {
                        return null;
                    }
                    [$i, $text, $atom, $widenedClass] = $class;
                    $out .= $text;
                    $widened = $widened || $widenedClass;
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
                    $groups[] = false;
                    $atom = null;
                    break;
                case ')':
                    if (--$depth < 0) {
                        return null;
                    }
                    $closed = array_pop($groups);
                    $groups[$depth] = $groups[$depth] || $closed;
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
                    $out .= $caseless && ctype_alpha($char) ? '['.$char.self::caseClosed([ord($char) => true], $unicode).']' : $char;
                    $atom = false;
            }

            if ($atom === true && ! $unicode && ($depth > 0 || ++$wideAtoms > 1)) {
                return null;
            }
        }

        if ($widened && ($unbounded > 1 || $nested)) {
            return null;
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
        // wider and true — for `\s` too, whose ECMA-262 reading parts from PCRE's on U+0085. Without it
        // PCRE's are ASCII, as ECMA-262's are — whose `\s` takes Unicode spaces too, which is wider and true.
        return match ($char) {
            'd', 'w', 's' => $unicode ? ['(?:['.self::ASCII_CLASS[$char].']|'.self::NON_ASCII.')', true] : ['\\'.$char, false],
            'D', 'W' => [$unicode ? '[^'.self::ASCII_CLASS[strtolower($char)].']' : '\\'.$char, true],
            // `\B` without `u` also holds between two bytes of one character, which ECMA-262 never sees.
            'b' => $unicode ? null : ['\\b', null],
            'A' => $anchors ? ['^', null] : null,
            'z' => $anchors ? ['$', null] : null,
            default => null,
        };
    }

    /**
     * The class opened at `$open`: the offset of its `]`, its translation, whether it is wide, and whether it
     * is widened by a Unicode-wide escape — or null
     * when it is not portable: a `]` first (a PCRE literal, an empty class to ECMA-262), an unescaped `[`
     * (PCRE's POSIX classes), a range backwards or reaching a class escape, or an escape the dialects part on.
     *
     * Caseless, its letters' case partners join it — negated too, since PCRE then refuses every partner.
     *
     * @return array{int, string, bool, bool}|null
     */
    private static function readClass(string $expression, int $open, bool $unicode, bool $caseless): ?array
    {
        $length = strlen($expression);
        $i = $open + 1;
        $negated = ($expression[$i] ?? '') === '^';
        if ($negated) {
            $i++;
        }

        $body = '';
        $widened = false;
        // The ASCII characters the class names one by one or by range, keyed by code.
        $members = [];
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

                if ($caseless) {
                    $body .= self::caseClosed($members, $unicode);
                }

                return $widened
                    ? [$i, '(?:['.$body.']|'.self::NON_ASCII.')', true, true]
                    : [$i, ($negated ? '[^' : '[').$body.']', $negated, false];
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

            // Caseless, a literal `-` is escaped, so no partner appended after it reads as a range with it.
            $text = $caseless && $char === '-' ? '\\-' : $char;
            if ($char === '\\') {
                $property = self::property($expression, $i + 1, $unicode, $caseless);
                if ($property !== null) {
                    // A property is a Unicode-wide escape: its ASCII half, and the class widened.
                    if ($range || $negated) {
                        return null;
                    }
                    [$i, $ascii] = $property;
                    $body .= $ascii;
                    $widened = true;
                    $previous = null;
                    $afterClassEscape = true;

                    continue;
                }

                $escaped = $expression[++$i] ?? '';
                if ($escaped === '' || ! self::printable($escaped)) {
                    return null;
                }

                if (isset(self::ASCII_CLASS[$escaped])) {
                    // Negated, a Unicode-wide escape would negate its non-ASCII half too; and ECMA-262's `\s`
                    // is wider than PCRE's, so `[^\s]` would refuse spaces PCRE accepts.
                    if ($range || ($negated && ($unicode || $escaped === 's'))) {
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
                $members += array_fill_keys(range(ord($previous), ord($char)), true);
                $range = false;
                $previous = null;

                continue;
            }

            $members[ord($char)] = true;
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

    /**
     * Whether a quantifier lets its atom match more than once.
     */
    private static function repeats(string $quantifier): bool
    {
        // `?`, or a count whose bounds are both at most one; the quantifier may be lazy.
        return preg_match('/\A(?:\?|\{0*[01](?:,0*[01])?})\??\z/', $quantifier) !== 1;
    }

    /**
     * A property escape (`\pL`, `\p{Lu}`, `\P{N}`) at `$at` under `u`: the offset of its last character and
     * its ASCII half as class members, read off PCRE itself — null for anything else, and for a property
     * without `u`, where PCRE reads each byte as a Latin-1 character.
     *
     * Caseless, PCRE2 before 10.45 reads `\p{Lu}`, `\p{Ll}` and `\p{Lt}` as written and from 10.45 as `\p{Lc}`
     * (their negations likewise), so these take both readings — whichever PCRE the build or the server runs.
     *
     * @return array{int, string}|null
     */
    private static function property(string $expression, int $at, bool $unicode, bool $caseless): ?array
    {
        if (! $unicode || preg_match('/\G[pP](?:\{(\^?)([A-Za-z_&]+)}|[A-Za-z])/', $expression, $match, 0, $at) !== 1) {
            return null;
        }

        $readings = [$match[0]];
        // PCRE2 matches a property name ignoring case and underscores.
        if ($caseless && in_array(strtolower(str_replace('_', '', $match[2] ?? '')), ['lu', 'll', 'lt'], true)) {
            $readings[] = $match[0][0].'{'.($match[1] ?? '').'Lc}';
        }

        $members = [];
        foreach ($readings as $reading) {
            $regex = '/\A\\'.$reading.'\z/u';
            for ($code = 0; $code < 0x80; $code++) {
                $matched = @preg_match($regex, chr($code));
                if ($matched === false) {
                    return null;
                }
                if ($matched === 1) {
                    $members[$code] = true;
                }
            }
        }

        return [$at + strlen($match[0]) - 1, self::members($members)];
    }

    /**
     * The class members a caseless PCRE adds to the ASCII characters given: each letter's other case, and
     * under `u` the non-ASCII characters it folds onto a letter.
     *
     * @param  array<int, true>  $members
     */
    private static function caseClosed(array $members, bool $unicode): string
    {
        $closed = $members;
        foreach ($members as $code => $_) {
            $char = chr($code);
            if (ctype_alpha($char)) {
                $closed[ord(ctype_lower($char) ? strtoupper($char) : strtolower($char))] = true;
            }
        }

        $text = self::members(array_diff_key($closed, $members));
        if ($unicode) {
            foreach (self::CASE_PARTNERS as $letter => $partner) {
                if (isset($closed[ord($letter)])) {
                    $text .= $partner;
                }
            }
        }

        return $text;
    }

    /**
     * ASCII characters as class members, runs of three or more as ranges, each escaped as ECMA-262 reads it
     * in both modes.
     *
     * @param  array<int, true>  $members
     */
    private static function members(array $members): string
    {
        ksort($members);
        $codes = array_keys($members);
        $text = '';
        $count = count($codes);
        for ($i = 0; $i < $count; $i = $end + 1) {
            $end = $i;
            while ($end + 1 < $count && $codes[$end + 1] === $codes[$end] + 1) {
                $end++;
            }
            $text .= $end - $i >= 2
                ? self::member($codes[$i]).'-'.self::member($codes[$end])
                : implode('', array_map(self::member(...), array_slice($codes, $i, $end - $i + 1)));
        }

        return $text;
    }

    private static function member(int $code): string
    {
        $char = chr($code);
        if (! self::printable($char)) {
            return sprintf('\\x%02X', $code);
        }

        return str_contains('\\]^-[', $char) ? '\\'.$char : $char;
    }

    private static function printable(string $char): bool
    {
        $code = ord($char);

        return $code >= 0x20 && $code <= 0x7E;
    }
}
