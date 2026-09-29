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
    $this->values = ['latest', 'a', 'ab', 'abc', 'a-b', 'a/b', '7', '42', '2024', '٣', '٣٣', '𝟎', '𝟎𝟎𝟎𝟎', 'é', 'éé', '😀', '😀😀', 'a😀', '日本', ' ', "\u{85}", "\u{A0}"];
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
    'an unescaped [ in a class' => ['[a[]', true, null],
    '\S' => ['\S+', false, null],
    'a Unicode property' => ['\p{L}+', true, null],
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
