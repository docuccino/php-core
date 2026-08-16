<?php

declare(strict_types=1);

use Docuccino\Core\Support\PlainText;

it('escapes what steers a terminal rather than showing in it', function (string $raw, string $escaped): void {
    expect(PlainText::of('a'.$raw.'b'))->toBe('a'.$escaped.'b');
})->with([
    'NUL' => ["\x00", '\x00'],
    'escape' => ["\x1B", '\x1B'],
    'carriage return' => ["\r", '\x0D'],
    'newline' => ["\n", '\x0A'],
    'DEL' => ["\x7F", '\x7F'],
    'C1 padding (U+0080)' => ["\u{0080}", '\u{0080}'],
    'C1 control sequence introducer (U+009B)' => ["\u{009B}", '\u{009B}'],
    'C1 application program command (U+009F)' => ["\u{009F}", '\u{009F}'],
    'arabic letter mark (U+061C)' => ["\u{061C}", '\u{061C}'],
    'left-to-right mark (U+200E)' => ["\u{200E}", '\u{200E}'],
    'right-to-left mark (U+200F)' => ["\u{200F}", '\u{200F}'],
    'left-to-right embedding (U+202A)' => ["\u{202A}", '\u{202A}'],
    'right-to-left override (U+202E)' => ["\u{202E}", '\u{202E}'],
    'left-to-right isolate (U+2066)' => ["\u{2066}", '\u{2066}'],
    'pop directional isolate (U+2069)' => ["\u{2069}", '\u{2069}'],
]);

it('leaves the characters either side of the escaped ranges alone', function (string $raw): void {
    // The escaped ranges are matched as UTF-8 byte sequences, so their neighbours are what would catch an
    // off-by-one in the byte arithmetic.
    expect(PlainText::of($raw))->toBe($raw);
})->with([
    'space' => ' ',
    'tilde (U+007E)' => '~',
    'no-break space (U+00A0), just past the C1 block' => "\u{00A0}",
    'arabic semicolon (U+061B), just before the letter mark' => "\u{061B}",
    'zero-width joiner (U+200D), just before the marks' => "\u{200D}",
    'hyphen (U+2010), just past the marks' => "\u{2010}",
    'narrow no-break space (U+202F), just past the overrides' => "\u{202F}",
    'U+2065, just before the isolates' => "\u{2065}",
    'U+206A, just past the isolates' => "\u{206A}",
]);

it('round-trips legitimate text byte for byte', function (): void {
    $text = 'café — 日本語 🎉 Ünicode';

    expect(PlainText::of($text))->toBe($text);
});

it('leaves malformed UTF-8 as it found it rather than throwing or blanking it', function (): void {
    // A `/u` pattern returns null on input like this, and casting that to a string would swallow the whole
    // line — losing the operator more than the escape sequence ever would.
    expect(PlainText::of("lone\xFFbyte"))->toBe("lone\xFFbyte");
});

it('still escapes a C1 control smuggled in beside malformed bytes', function (): void {
    expect(PlainText::of("\xFF\u{009B}31m"))->toBe("\xFF".'\u{009B}31m');
});

it('escapes a direction override that would otherwise reverse the line it appears in', function (): void {
    expect(PlainText::of("GET /forms\u{202E}exe.tnetnoc"))->toBe('GET /forms\u{202E}exe.tnetnoc');
});
