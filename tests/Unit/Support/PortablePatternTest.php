<?php

declare(strict_types=1);

use Docuccino\Core\Support\PortablePattern;

/**
 * A published `pattern` is ECMA-262, and a consumer may compile it with the `u` flag or without; the
 * expression it is read from is PCRE, and PHP's `u` is UTF *and* Unicode properties, so `\d` there is
 * every script's digit. A pattern narrower than the expression marks a value the server accepts invalid,
 * so each accepted row must accept, in BOTH ECMA-262 modes, every value PCRE does — `$ecma` below reads a
 * pattern as each mode does (without `u` a character outside the BMP is two UTF-16 units, each of them
 * non-ASCII), and a refused row carries, where that reading can show it, the value on which publishing
 * the expression verbatim would be narrower.
 */
beforeEach(function (): void {
    // ECMA-262's class escapes are ASCII, so PCRE reads the pattern in UTF mode without Unicode properties.
    $this->ecma = static function (string $pattern, string $value, bool $unicodeFlag): bool {
        $units = $unicodeFlag ? $value : (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{E000}\u{E001}", $value);

        // `D`: ECMA-262's `$` without `m` is the very end, never before a final `\n` as PCRE's own is.
        return preg_match('/(*UTF)'.str_replace('/', '\/', $pattern).'/D', $units) === 1;
    };
    $this->values = ['latest', 'a', 'ab', 'abc', 'a-b', 'a/b', '7', '42', '2024', '٣', '٣٣', '𝟎', '𝟎𝟎𝟎𝟎', 'é', 'éé', '😀', '😀😀', 'a😀', '日本', ' ', "\u{85}", "\u{A0}",
        'A', 'Z', 'Élan', '𝐀𝐁', 'a b', "a\tb", "\u{2028}", "\u{3000}", 'Anne-Marie', 'Zoë Smith', "\x7F", '@', '`'];
});

it('anchors an expression, exactly or wider, never narrower than PCRE reads it', function (string $expression, bool $unicode, string $pattern): void {
    expect(PortablePattern::anchored($expression, unicode: $unicode, anchors: false))->toBe($pattern);

    // Grouped, as the router embeds a segment's expression in the path's own regex.
    $server = '{^(?:'.$expression.')$}sD'.($unicode ? 'u' : '');
    $accepted = array_values(array_filter($this->values, static fn (string $v): bool => preg_match($server, $v) === 1));
    foreach ($accepted as $value) {
        expect(($this->ecma)($pattern, $value, true))->toBeTrue("u-mode refuses {$value}")
            ->and(($this->ecma)($pattern, $value, false))->toBeTrue("non-u refuses {$value}");
    }
})->with([
    'a literal' => ['latest', true, '^latest$'],
    'a class and quantifier' => ['[a-z0-9]+', true, '^[a-z0-9]+$'],
    'a count over an ASCII class' => ['[0-9]{4}', true, '^[0-9]{4}$'],
    // Unbounded, a negated class matches a character outside the BMP as two units, and still matches.
    'a negated class, unbounded' => ['[^/]+', true, '^[^/]+$'],
    'a literal dash first and last in a class' => ['[-a-z_-]+', true, '^[-a-z_-]+$'],
    'an escaped dash in a class' => ['[a\-z]', true, '^[a\-z]$'],
    'the escaped syntax characters' => ['\.\*\+\?\(\)\[\]\{\}\|\/\\\\\^\$', true, '^\.\*\+\?\(\)\[\]\{\}\|\/\\\\\^\$$'],
    // PCRE reads other escaped punctuation as itself; ECMA-262 refuses the escape under `u`.
    'escaped punctuation' => ['a\-b\#c', true, '^a-b#c$'],
    'counted quantifiers' => ['[0-9]{4}-[0-9]{2,}-[0-9]{1,2}', true, '^[0-9]{4}-[0-9]{2,}-[0-9]{1,2}$'],
    'lazy quantifiers' => ['a+?b*?c??d{2}?', true, '^a+?b*?c??d{2}?$'],
    'a non-capturing group' => ['(?:ab)+', true, '^(?:ab)+$'],
    'a capturing group' => ['(ab)+', true, '^(ab)+$'],
    'a top-level alternation' => ['a|b', true, '^(?:a|b)$'],
    'a nested alternation only' => ['x(?:a|b)', true, '^x(?:a|b)$'],
    'printable punctuation' => ['a@b~c,d:e=f!g', true, '^a@b~c,d:e=f!g$'],
    // Under `u` a class escape is every script's; the ASCII class or any non-ASCII character is wider, and true.
    '\d under u' => ['\d+', true, '^(?:[0-9]|[^\x00-\x7F])+$'],
    '\w under u' => ['\w+', true, '^(?:[0-9A-Za-z_]|[^\x00-\x7F])+$'],
    '\D under u' => ['\D+', true, '^[^0-9]+$'],
    'a class escape in a class under u' => ['[\da-f]+', true, '^(?:[0-9a-f]|[^\x00-\x7F])+$'],
    'a class escape with a trailing dash' => ['[\w.-]+', true, '^(?:[0-9A-Za-z_.-]|[^\x00-\x7F])+$'],
    // Without `u` PCRE's class escapes are ASCII, as ECMA-262's are.
    // …and so is `\s`, whose ASCII half ECMA-262 shares but whose U+0085 ECMA-262 does not take.
    '\s under u' => ['\s+', true, '^(?:[\t-\r ]|[^\x00-\x7F])+$'],
    '\s in a class under u' => ['[\s,]+', true, '^(?:[\t-\r ,]|[^\x00-\x7F])+$'],
    // A Unicode property's ASCII half is read off PCRE itself; any non-ASCII character is wider, and true.
    'a Unicode property' => ['\p{L}+', true, '^(?:[A-Za-z]|[^\x00-\x7F])+$'],
    'a one-letter property in a class' => ['[\pL\s\-]+', true, '^(?:[A-Za-z\t-\r \-]|[^\x00-\x7F])+$'],
    'a negated property' => ['\P{L}+', true, '^(?:[\x00-@\[-`{-\x7F]|[^\x00-\x7F])+$'],
    'a property with no ASCII member' => ['\p{Han}+', true, '^[^\x00-\x7F]+$'],
    '\d without u' => ['\d{4}', false, '^\d{4}$'],
    '\s without u' => ['a\sb', false, '^a\sb$'],
    'a class escape in a negated class without u' => ['[^\d]+', false, '^[^\d]+$'],
]);

it('refuses an expression the two dialects read differently', function (string $expression, bool $unicode, ?string $witness): void {
    expect(PortablePattern::anchored($expression, unicode: $unicode, anchors: false))->toBeNull()
        ->and(PortablePattern::translate($expression, unicode: $unicode, anchors: false))->toBeNull()
        ->and(PortablePattern::literals($expression))->toBeNull();

    if ($witness !== null) {
        // PCRE accepts the witness, and the expression published as written refuses it without `u`.
        expect(preg_match('{^(?:'.$expression.')$}sD'.($unicode ? 'u' : ''), $witness))->toBe(1)
            ->and(($this->ecma)('^'.$expression.'$', $witness, false))->toBeFalse();
    }
})->with([
    'empty' => ['', true, null],
    // A count over a wide atom counts code points (or bytes) in PCRE and UTF-16 units in ECMA-262.
    'a counted negated class' => ['[^/]{2}', true, '😀😀'],
    'a lone negated class' => ['[^/]', true, '😀'],
    'an optional negated class' => ['a[^/]?', true, 'a😀'],
    'a counted \d under u' => ['\d{4}', true, '𝟎𝟎𝟎𝟎'],
    'a count from two up over a wide atom' => ['[^/]{2,}', false, 'é'],
    'two wide atoms without u' => ['[^/]+[^a]+', false, 'é'],
    'a wide atom in a group without u' => ['(?:[^,]+)+', false, null],
    // Under `u` PCRE's `\s` takes U+0085, which ECMA-262's does not; `\b` follows `\w`.
    '\s under u' => ['\s', true, "\u{85}"],
    '\b under u' => ['\bx', true, null],
    'a negated class escape under u' => ['[^\d]+', true, null],
    '\s in a class under u' => ['[\s]', true, null],
    // ECMA-262's `\s` takes U+00A0, which PCRE's does not without `u`, so `[^\s]` would refuse it.
    '\s in a negated class' => ['[^\s]+', false, null],
    'a range reaching a class escape' => ['[\d-z]', false, null],
    'a range from a class escape' => ['[a-\d]', false, null],
    'a POSIX class' => ['[[:alpha:]]+', true, null],
    // A count over a property counts code points in PCRE and UTF-16 units in ECMA-262.
    'a counted property' => ['\p{L}{2}', true, '𝐀𝐁'],
    'a lone property' => ['\pL', true, null],
    'a property in a negated class' => ['[^\pL]+', true, null],
    'a range reaching a property' => ['[a-\pL]', true, null],
    'a range from a property' => ['[\pL-z]', true, null],
    'a property PCRE does not know' => ['\p{Nope}+', true, null],
    '\s in a negated class under u' => ['[^\s]+', true, null],
    'an unescaped [ in a class' => ['[a[]', true, null],
    '\S' => ['\S+', false, null],
    // Without `u` PCRE reads each byte as a Latin-1 character, so `\p{L}` takes one byte of `é`.
    'a Unicode property without u' => ['\p{L}+', false, null],
    'a hex escape' => ['\x41', true, null],
    'an identity escape of a letter' => ['\q', true, null],
    'a trailing backslash' => ['a\\', true, null],
    // `.` stops at `\n` alone in PCRE (and takes it under `s`), at `\r`, U+2028 and U+2029 too in ECMA-262.
    'a dot' => ['a.b', true, null],
    'a string anchor where anchors do not stand' => ['\Aa', true, null],
    'an inner start anchor where anchors do not stand' => ['a|^b', true, null],
    'an inner end anchor where anchors do not stand' => ['a$|b', true, null],
    'an inline flag' => ['(?i)abc', true, null],
    'a named group' => ['(?<id>[0-9]+)', true, null],
    'a Python-named group' => ['(?P<id>[0-9]+)', true, null],
    'a lookahead' => ['(?=a)a', true, null],
    'an atomic group' => ['(?>a+)', true, null],
    'a possessive quantifier' => ['a++', true, null],
    'a possessive count' => ['a{2}+', true, null],
    // Literals to PCRE, errors to a `u`-mode ECMA-262 engine.
    'a lone brace' => ['a{', true, null],
    'an open-ended count' => ['a{,3}', true, null],
    'a lone closing brace' => ['a}', true, null],
    'a lone closing bracket' => ['a]', true, null],
    // A `]` first in a class is a literal to PCRE and closes an empty class in ECMA-262.
    'a leading ] in a class' => ['[]a]', true, null],
    'a leading ] in a negated class' => ['[^]a]', true, null],
    'an unterminated class' => ['[a-z', true, null],
    'a backwards range' => ['[z-a]', true, null],
    'a backwards count' => ['a{3,2}', true, null],
    'unbalanced open' => ['(ab', true, null],
    'unbalanced close' => ['ab)', true, null],
    'a quantifier on nothing' => ['*a', true, null],
    'a quantifier after a group opening' => ['(+a)', true, null],
    'a quantifier after an alternation' => ['a|+b', true, null],
    'a double quantifier' => ['a**', true, null],
    'a non-ASCII literal' => ['café', true, null],
    'a control character' => ["a\tb", true, null],
]);

it('reads anchors and string anchors where the expression stands alone', function (string $expression, bool $endOnly, string $pattern): void {
    // A search: PCRE and the pattern both match anywhere unless the author anchored, and `\A`/`\z` are
    // the start and the very end, which is what ECMA-262's `^`/`$` are without `m`. PCRE's `$` without
    // `D` also matches before a final `\n`, so the pattern takes that `\n` too.
    expect(PortablePattern::translate($expression, unicode: false, anchors: true, endOnly: $endOnly))->toBe($pattern);

    $server = '/'.$expression.'/'.($endOnly ? 'D' : '');
    $values = [...$this->values, 'id', 'b', "a\n", "b\n", "abc\n", "a\n\n", "id\n", "\n"];
    $accepted = array_values(array_filter($values, static fn (string $v): bool => preg_match($server, $v) === 1));
    expect($accepted)->not->toBe([]);
    foreach ($accepted as $value) {
        expect(($this->ecma)($pattern, $value, true))->toBeTrue('u-mode refuses '.json_encode($value))
            ->and(($this->ecma)($pattern, $value, false))->toBeTrue('non-u refuses '.json_encode($value));
    }
})->with([
    'unanchored' => ['[0-9]', false, '[0-9]'],
    'anchored' => ['^[a-z]+$', false, '^[a-z]+\n?$'],
    'anchored, under D' => ['^[a-z]+$', true, '^[a-z]+$'],
    'string anchors' => ['\A[a-z]+\z', false, '^[a-z]+$'],
    'an inner anchor' => ['^a$|^b$', false, '^a\n?$|^b\n?$'],
    'an end anchor closing a group' => ['^(?:a|b)$', false, '^(?:a|b)\n?$'],
    'an end anchor inside a group' => ['^(a$)', false, '^(a\n?$)'],
    'a quantified group closed by an end anchor' => ['^(?:a$)+', false, '^(?:a\n?$)+'],
    'a repeated end anchor' => ['a$$', false, 'a\n?$\n?$'],
    'a word boundary without u' => ['\bid\b', false, '\bid\b'],
]);

it('refuses an anchor the two dialects read differently', function (string $expression, bool $unicode, bool $endOnly, ?string $witness): void {
    expect(PortablePattern::translate($expression, unicode: $unicode, anchors: true, endOnly: $endOnly))->toBeNull();

    if ($witness !== null) {
        // PCRE accepts the witness, and the expression published as written refuses it.
        expect(preg_match('/'.$expression.'/'.($endOnly ? 'D' : '').($unicode ? 'u' : ''), $witness))->toBe(1)
            ->and(($this->ecma)($expression, $witness, false))->toBeFalse();
    }
})->with([
    // ECMA-262 has no syntax for a quantified assertion.
    'a quantified anchor' => ['^*a', false, false, null],
    '\b under u' => ['\bx', true, false, null],
    // Without `u` PCRE reads bytes, and two bytes of one non-ASCII character are both non-word.
    '\B without u' => ['x\Ba', false, false, null],
    'a non-word boundary without u' => ['\B', false, false, "x\u{A0}a"],
    '\B under u' => ['\B', true, false, null],
    // After a `$` that also matches before a final `\n`, anything that could take that `\n` would find
    // it gone from the pattern.
    'an atom after an end anchor' => ['a$\s', false, false, "a\n"],
    'a class after an end anchor' => ['a$[^x]', false, false, "a\n"],
    'a later branch of the group an end anchor sits in' => ['(a$|b)\s', false, false, null],
    'a group after an end anchor' => ['a$(b)', false, false, null],
    'a string anchor after an end anchor' => ['a$\z', false, false, null],
]);

it('reads an alternation of literal strings as its values', function (string $expression, array $values): void {
    expect(PortablePattern::literals($expression))->toBe($values);
})->with([
    'one literal' => ['latest', ['latest']],
    'a whereIn set' => ['draft|published|archived', ['draft', 'published', 'archived']],
    'escaped syntax characters are themselves' => ['v1\.0|v2\.0', ['v1.0', 'v2.0']],
    'escaped punctuation is itself' => ['en\-GB|a\#b', ['en-GB', 'a#b']],
    'punctuation that is no syntax' => ['en-GB|pt_BR|a/b', ['en-GB', 'pt_BR', 'a/b']],
    // The value list is a set; the first spelling of a repeat keeps its place.
    'a repeat' => ['a|b|a', ['a', 'b']],
]);

it('reads no values from an expression matching more than its literals', function (string $expression): void {
    expect(PortablePattern::literals($expression))->toBeNull();
})->with([
    'a class' => ['[ab]'],
    'a quantifier' => ['ab+'],
    'a group' => ['(?:a|b)'],
    'a dot' => ['a.b'],
    'a class escape' => ['\d'],
    'an empty branch' => ['a||b'],
    'a trailing empty branch' => ['a|'],
    'an escaped bar' => ['a\|b'],
    'a non-ASCII literal' => ['café'],
]);

it('reads a caseless expression as every case of its letters, never narrower than PCRE reads it', function (string $expression, bool $unicode, string $pattern): void {
    expect(PortablePattern::translate($expression, unicode: $unicode, anchors: true, endOnly: true, caseless: true))->toBe($pattern);

    // PHP's `i` folds ASCII letters onto each other, and under `u` also the Kelvin sign onto `k` and the
    // long s onto `s`; each value below is tried as written, upper-cased, and with those two in place.
    $samples = [...$this->values, 'LATEST', 'LaTeSt', 'ks', 'KS', 'Ks', '#abc', '#ABC', '#aBc', '#FFFFFF', '#12', 'AB', 'aB', '0f', 'F0', '-a', 'A-', 'a-A', '42-Ab', 'Z', 'z', '[', '_', "\u{130}", "\u{131}"];
    $values = array_values(array_unique(array_merge(...array_map(static fn (string $v): array => [
        $v, strtoupper($v), str_replace(['k', 's'], ["\u{212A}", "\u{17F}"], strtolower($v)),
    ], $samples))));

    $server = '/'.$expression.'/iD'.($unicode ? 'u' : '');
    $accepted = array_values(array_filter($values, static fn (string $v): bool => preg_match($server, $v) === 1));
    expect($accepted)->not->toBe([]);
    foreach ($accepted as $value) {
        expect(($this->ecma)($pattern, $value, true))->toBeTrue('u-mode refuses '.json_encode($value))
            ->and(($this->ecma)($pattern, $value, false))->toBeTrue('non-u refuses '.json_encode($value));
    }
})->with([
    'a literal' => ['^latest$', false, '^[lL][aA][tT][eE][sS][tT]$'],
    'a literal under u' => ['^ks$', true, "^[kK\u{212A}][sS\u{17F}]$"],
    'a class' => ['^[a-z]+$', false, '^[a-zA-Z]+$'],
    'a class under u' => ['^[a-z]+$', true, "^[a-zA-Z\u{212A}\u{17F}]+$"],
    'an uppercase range' => ['^[A-F0-9]{2}$', false, '^[A-F0-9a-f]{2}$'],
    'a range across the letters' => ['^[Z-a]$', false, '^[Z-aAz]$'],
    // PCRE refuses every case of a letter a negated class names, the Kelvin sign and long s included.
    'a negated class' => ['^[^a-z]+$', true, "^[^a-zA-Z\u{212A}\u{17F}]+$"],
    // A literal dash is escaped, so the partners appended after it never read as a range.
    'a literal dash beside partners' => ['^[-a]+$', false, '^[\-aA]+$'],
    'a counted class in a group' => ['^#(?:[0-9a-f]{3}){1,2}$', false, '^#(?:[0-9a-fA-F]{3}){1,2}$'],
    // Class escapes and punctuation have no case.
    'class escapes' => ['^\d+-\w+$', false, '^\d+-\w+$'],
    'a property' => ['^\p{L}+$', true, '^(?:[A-Za-z]|[^\x00-\x7F])+$'],
]);

it('reads a caseless case property as every PCRE reads it, the same on every PCRE', function (string $expression, string $before, string $after, string $pattern): void {
    // PCRE2 before 10.45 reads a caseless `\p{Lu}`, `\p{Ll}` or `\p{Lt}` as written, and from 10.45 as
    // `\p{Lc}`: the server may run either, so the pattern takes both, and is stated here so that the bytes
    // cannot follow the PCRE the build ran on. Each reading below is written out case-sensitively, which
    // every PCRE reads alike.
    expect(PortablePattern::translate($expression, unicode: true, anchors: true, endOnly: true, caseless: true))->toBe($pattern);

    $values = [...array_map(chr(...), range(0, 0x7F)), 'É', 'é', 'ǅ', '٣'];
    foreach ($values as $value) {
        if (preg_match('/'.$before.'/uD', $value) === 1 || preg_match('/'.$after.'/uD', $value) === 1) {
            expect(($this->ecma)($pattern, $value, true))->toBeTrue('u-mode refuses '.json_encode($value))
                ->and(($this->ecma)($pattern, $value, false))->toBeTrue('non-u refuses '.json_encode($value));
        }
    }
})->with([
    'uppercase' => ['^\p{Lu}+$', '^\p{Lu}+$', '^\p{Lc}+$', '^(?:[A-Za-z]|[^\x00-\x7F])+$'],
    'lowercase' => ['^\p{Ll}+$', '^\p{Ll}+$', '^\p{Lc}+$', '^(?:[A-Za-z]|[^\x00-\x7F])+$'],
    // Titlecase letters are none of them ASCII, so the later reading alone takes any ASCII letter.
    'titlecase' => ['^\p{Lt}+$', '^\p{Lt}+$', '^\p{Lc}+$', '^(?:[A-Za-z]|[^\x00-\x7F])+$'],
    // PCRE2 reads a property name ignoring case and underscores.
    'a loosely spelled name' => ['^\p{l_u}+$', '^\p{Lu}+$', '^\p{Lc}+$', '^(?:[A-Za-z]|[^\x00-\x7F])+$'],
    'a negated property' => ['^\P{Lu}+$', '^\P{Lu}+$', '^\P{Lc}+$', '^(?:[\x00-@\[-\x7F]|[^\x00-\x7F])+$'],
    'a caret-negated property' => ['^\p{^Ll}+$', '^\p{^Ll}+$', '^\P{Lc}+$', '^(?:[\x00-`{-\x7F]|[^\x00-\x7F])+$'],
    'a doubly negated property' => ['^\P{^Lt}+$', '^\p{Lt}+$', '^\p{Lc}+$', '^(?:[A-Za-z]|[^\x00-\x7F])+$'],
    'in a class' => ['^[\p{Lu}0-9]+$', '^[\p{Lu}0-9]+$', '^[\p{Lc}0-9]+$', '^(?:[A-Za-z0-9]|[^\x00-\x7F])+$'],
    // Any other property caseless PCRE reads as written, on either side of 10.45.
    'a letter property' => ['^\p{L}+$', '^\p{L}+$', '^\p{L}+$', '^(?:[A-Za-z]|[^\x00-\x7F])+$'],
]);

it('refuses a second repeat beside a widened atom, which a consumer could split a run with', function (string $expression): void {
    // Widened, the atoms all take a character such as `€`, which PCRE's refuse at once; so two loops, or a
    // loop inside a repeat, can divide a run of them many ways. Some of these PCRE could never be slow on
    // (`-` parts the name), and are refused all the same: the rule is the loop count, not a proof.
    expect(PortablePattern::translate($expression, unicode: true, anchors: true, endOnly: true))->toBeNull()
        ->and(PortablePattern::anchored(substr($expression, 1, -1), unicode: true, anchors: false))->toBeNull();
})->with([
    'a nested repeat' => ['^(?:\pL+-?)*$'],
    'a repeated group of one loop' => ['^(?:\pL+)*$'],
    'a counted group of one loop' => ['^(?:\pL+){2,}$'],
    'a bounded repeat of one loop' => ['^(?:\pL+){2}$'],
    'a capturing repeat' => ['^(\w+)+$'],
    'adjacent loops' => ['^\pL+\s*\pL*\s*\pL*$'],
    'a widened loop beside a plain one' => ['^[a-z]*\w+$'],
    'alternated loops' => ['^(?:\pL+|\d+)$'],
    'loops in a group taken once' => ['^(?:\pL+\s+)?$'],
    'a hyphenated name' => ['^[\pL]+(?:-[\pL]+)*$'],
    'a delimited list' => ['^\d+(?:,\d+)*$'],
]);

it('publishes no pattern a backtracking consumer is slower on than the server', function (): void {
    // The consumer's engine is modelled by PCRE itself with every optimisation that skips a search off, the
    // work counted as the backtrack limit it needs. Over expressions built from the subset, every published
    // pattern must grow at most quadratically on values of non-ASCII characters the server's atoms refuse,
    // alone or pumped with separators, unless the server works as hard on the same value.
    $cap = 1_000_000;
    $verbs = '(*NO_JIT)(*NO_AUTO_POSSESS)(*NO_START_OPT)(*NO_DOTSTAR_ANCHOR)';
    $steps = static function (string $regex, string $subject) use ($cap): int {
        for ($limit = 32; ; $limit = min($limit * 2, $cap)) {
            ini_set('pcre.backtrack_limit', (string) $limit);
            if (@preg_match($regex, $subject) !== false) {
                break;
            }
            if ($limit >= $cap) {
                return $cap;
            }
        }
        [$low, $high] = [intdiv($limit, 2), $limit];
        for ($k = 0; $k < 6; $k++) {
            $middle = intdiv($low + $high, 2);
            ini_set('pcre.backtrack_limit', (string) $middle);
            if (@preg_match($regex, $subject) !== false) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return $high;
    };

    $euro = '€';
    $attacks = ['€…' => static fn (int $n): string => str_repeat($euro, $n).'!'];
    foreach (['-', ' ', 'a', '0', ','] as $separator) {
        $attacks["(€{$separator})…"] = static fn (int $n): string => str_repeat($euro.$separator, $n).'!';
    }
    foreach (['a', ' '] as $around) {
        $attacks["{$around}…€{$around}…"] = static fn (int $n): string => str_repeat($around, $n).$euro.str_repeat($around, $n).'!';
    }

    // Sequences of up to three terms, each an atom and a quantifier or a group — alternated, nested twice.
    $atoms = ['\pL', '\w', '\d', '\s', '[\pL\s\-]', '\W', '[a-z]', 'a', '-', ' ', '[^,]', '[\d,]'];
    $quantifiers = ['', '+', '*', '?', '{1,}', '{2}', '{1,3}', '+?'];
    mt_srand(20260929);
    $pick = static fn (array $of): string => $of[mt_rand(0, count($of) - 1)];
    $term = null;
    $sequence = static function (int $depth) use (&$term): string {
        $text = '';
        for ($k = mt_rand(1, 3); $k > 0; $k--) {
            $text .= $term($depth);
        }

        return $text;
    };
    $term = static function (int $depth) use (&$sequence, $pick, $atoms, $quantifiers): string {
        if ($depth < 2 && mt_rand(0, 3) === 0) {
            $body = $sequence($depth + 1).(mt_rand(0, 2) === 0 ? '|'.$sequence($depth + 1) : '');

            return '(?:'.$body.')'.$pick(['+', '*', '?', '{2,}', '{1,3}', '']);
        }

        return $pick($atoms).$pick($quantifiers);
    };
    $expressions = ['(?:\pL+-?)*', '(\w+)+', '\pL+\s*\pL*\s*\pL*', '(?:\pL+\s+)*\pL+', '[\pL\s\-]+', '\d+'];
    while (count($expressions) < 1500) {
        $expressions[] = $sequence(0);
    }

    $limit = ini_get('pcre.backtrack_limit');
    $published = 0;
    $widened = 0;
    $slower = [];
    try {
        foreach (array_unique($expressions) as $expression) {
            foreach (['^'.$expression.'$' => true, $expression => false] as $source => $anchors) {
                $pattern = PortablePattern::translate($source, unicode: true, anchors: $anchors, endOnly: true);
                if ($pattern === null) {
                    continue;
                }
                $published++;
                $widened += str_contains($pattern, '[^\x00-\x7F]') ? 1 : 0;
                foreach ($attacks as $name => $attack) {
                    $short = $steps('/'.$verbs.'(*UTF)'.$pattern.'/D', $attack(20));
                    $long = $steps('/'.$verbs.'(*UTF)'.$pattern.'/D', $attack(40));
                    if ($long < $cap && ($long <= 4000 || $long <= 4.5 * $short)) {
                        continue;
                    }
                    $server = $steps('/'.$verbs.$source.'/uD', $attack(40));
                    if (4 * $server < $long) {
                        $slower[] = "{$source} as {$pattern} on {$name}: {$short} then {$long} steps, the server {$server}";
                    }
                }
            }
        }
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    expect($slower)->toBe([]);
    // A generator the translator refused wholesale would prove nothing.
    expect($published)->toBeGreaterThan(300)
        ->and($widened)->toBeGreaterThan(80);
});

it('spells every character PCRE folds onto an ASCII letter', function (): void {
    // The source of truth is PCRE itself: every non-ASCII code point `iu` matches to an ASCII letter.
    $folded = [];
    for ($code = 0x80; $code <= 0x10FFFF; $code++) {
        if (($code < 0xD800 || $code > 0xDFFF) && preg_match('/^[a-z]$/iu', mb_chr($code, 'UTF-8')) === 1) {
            $folded[] = mb_chr($code, 'UTF-8');
        }
    }

    // A sweep that found nothing proves nothing: PCRE folds at least the Kelvin sign onto `k`.
    expect($folded)->toContain("\u{212A}");
    foreach ($folded as $char) {
        foreach (range('a', 'z') as $letter) {
            if (preg_match('/^'.$letter.'$/iu', $char) !== 1) {
                continue;
            }
            foreach ([$letter, strtoupper($letter), '['.$letter.']+', '[^0-9]+'] as $expression) {
                $pattern = (string) PortablePattern::translate('^'.$expression.'$', unicode: true, anchors: true, endOnly: true, caseless: true);
                expect(($this->ecma)($pattern, $char, true))->toBeTrue("{$expression} refuses U+".dechex(mb_ord($char)))
                    ->and(($this->ecma)($pattern, $char, false))->toBeTrue();
            }
            // Negated, the class refuses it as PCRE does.
            $negated = (string) PortablePattern::translate('^[^'.$letter.']+$', unicode: true, anchors: true, endOnly: true, caseless: true);
            expect(preg_match('/^[^'.$letter.']+$/iu', $char))->toBe(0)
                ->and(($this->ecma)($negated, $char, true))->toBeFalse();
        }
    }
});
