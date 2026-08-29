<?php

declare(strict_types=1);

use Docuccino\Core\Provenance\PublishableText;

/*
 * What the two path passes over a diagnostic may read, and what they publish when they cannot read
 * it. Both are `preg_replace_callback` calls, and PCRE answers null on a resource limit rather than
 * throwing — so the fail-open answer is the original message, machine paths and all. The bound is
 * what keeps that from being reachable, and the refusal is what happens if it is anyway.
 */

it('hands back a message short enough to check, exactly as it arrived', function (string $case, string $text): void {
    expect(PublishableText::bounded($text))->toBe($text);
})->with([
    ['nothing at all', ''],
    ['an ordinary message', 'Could not open /app/root/app/X.php'],
    ['a message with multibyte text in it', 'Refusé « /app/root/café/x.php » — 3 fois'],
    ['a message exactly at the bound', str_repeat('a', PublishableText::MAX_BYTES)],
]);

it('cuts a message past the bound and marks where it stopped', function (): void {
    $bounded = PublishableText::bounded(str_repeat('a', PublishableText::MAX_BYTES + 1));

    expect($bounded)->toBe(str_repeat('a', PublishableText::MAX_BYTES).'…')
        ->and(strlen($bounded))->toBe(PublishableText::MAX_BYTES + 3);
});

it('never cuts a character in half, whatever width it is', function (string $case, int $width, string $character): void {
    // The document is JSON, and a JSON encoder will not carry a half-written character — so a cut
    // that landed inside one has to give the whole character up. UTF-8 is self-synchronising, so the
    // damage is always the last few bytes: this walks the cut across every byte of one character and
    // asks that what comes back is still valid, and that a character the cut CLEARED survives whole.
    foreach (range(0, $width) as $inside) {
        // `$inside` bytes of the character land in front of the cut and the rest behind it.
        $bounded = PublishableText::bounded(
            str_repeat('a', PublishableText::MAX_BYTES - $inside).$character.str_repeat('b', 64),
        );

        expect(preg_match('//u', $bounded))->toBe(1)
            ->and(substr($bounded, -3))->toBe('…')
            ->and(str_contains($bounded, $character))->toBe($inside === $width)
            ->and(strlen($bounded))->toBe(
                ($inside === 0 || $inside === $width ? PublishableText::MAX_BYTES : PublishableText::MAX_BYTES - $inside) + 3,
            );
    }
})->with([
    ['two bytes', 2, 'é'],
    ['three bytes', 3, '→'],
    ['four bytes', 4, '𝄞'],
]);

it('leaves a cut alone where the bytes at it are not a character at all', function (): void {
    // A message can arrive malformed — the passes are handed whatever somebody else threw — and
    // guessing at a repair would change text nobody wrote. Bytes that cannot be a lead byte are left
    // exactly where they are.
    $bounded = PublishableText::bounded(str_repeat('a', PublishableText::MAX_BYTES - 4).str_repeat("\x80", 4).'tail');

    expect($bounded)->toBe(str_repeat('a', PublishableText::MAX_BYTES - 4).str_repeat("\x80", 4).'…');
});

it('publishes a fixed refusal where a pass answered null, and the answer where it did not', function (): void {
    expect(PublishableText::orRefused(null))->toBe(PublishableText::REFUSED)
        ->and(PublishableText::orRefused('read app/X.php'))->toBe('read app/X.php')
        ->and(PublishableText::orRefused(''))->toBe('')
        // Nothing in it can be a function of the machine, or two builds of one codebase disagree.
        ->and(PublishableText::REFUSED)->not->toContain('/');
});
