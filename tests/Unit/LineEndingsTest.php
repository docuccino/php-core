<?php

declare(strict_types=1);

use Docuccino\Core\Support\LineEndings;

it('folds every line ending an editor writes to LF', function (string $raw, string $expected): void {
    expect(LineEndings::normalize($raw))->toBe($expected);
})->with([
    'LF is left alone' => ["a\nb\n", "a\nb\n"],
    'CRLF' => ["a\r\nb\r\n", "a\nb\n"],
    'lone CR' => ["a\rb\r", "a\nb\n"],
    'mixed in one string' => ["a\r\nb\rc\nd", "a\nb\nc\nd"],
    'no line ending at all' => ['a b', 'a b'],
    'empty' => ['', ''],
]);

it('leaves a CR that is already spelled out in the text alone', function (): void {
    // Only real bytes are folded — an escape sequence an author typed is text like any other.
    expect(LineEndings::normalize('a\\r\\nb'))->toBe('a\\r\\nb');
});
