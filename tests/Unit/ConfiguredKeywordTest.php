<?php

declare(strict_types=1);

use Docuccino\Core\Support\ConfiguredKeyword;

/*
 * The reading of a closed-set keyword, one bag at a time. Its whole point is that it REFUSES rather
 * than coerces, so most of what is below is the refusal: which values reach it, what each is called
 * back to its author, and the promise that the value it answers is the default it names.
 */

/** @var non-empty-list<string> */
const STYLES = ['type-array', 'anyof'];

it('answers a keyword the bag holds', function (string $keyword): void {
    $read = ConfiguredKeyword::read(['nullable' => $keyword], 'nullable', 'type-array', STYLES);

    expect($read->keyword)->toBe($keyword)
        ->and($read->refused)->toBeFalse()
        ->and($read->refusal('representation.nullable'))->toBeNull();
})->with(['type-array', 'anyof']);

it('answers the default for a key nobody wrote, and says nothing', function (): void {
    $read = ConfiguredKeyword::read([], 'nullable', 'type-array', STYLES);

    expect($read->keyword)->toBe('type-array')
        ->and($read->refused)->toBeFalse()
        ->and($read->refusal('representation.nullable'))->toBeNull();
});

it('refuses everything outside the set, answers the default, and says so', function (mixed $value, string $found): void {
    $read = ConfiguredKeyword::read(['nullable' => $value], 'nullable', 'type-array', STYLES);

    expect($read->keyword)->toBe('type-array')
        ->and($read->refused)->toBeTrue()
        ->and($read->refusal('representation.nullable'))->toBe(
            'representation.nullable is '.$found.', which is none of the values it takes'
            .' — it is read as "type-array", its default.',
        );
})->with([
    // The mistyped keyword, which is the whole reason this exists.
    'a near miss' => ['anyOf', 'the text "anyOf"'],
    'a typo' => ['tpye-array', 'the text "tpye-array"'],
    'an empty string' => ['', 'the text ""'],
    // YAML reads `no` and `off` as TEXT, so a value that looks like a switch arrives as a string.
    'a word YAML keeps as text' => ['no', 'the text "no"'],
    // `1.10` is the float 1.1 and a bare date is an int: neither is the text somebody wrote.
    'a decimal' => [1.1, 'the decimal number 1.1'],
    'a whole number' => [3, 'the whole number 3'],
    'a boolean' => [true, 'the boolean true'],
    // A key written with nothing after the colon: an intent expressed and unreadable, so it is
    // refused rather than treated as an absence.
    'a written null' => [null, 'empty'],
    'a list' => [['anyof'], 'a list'],
    'a map' => [['style' => 'anyof'], 'a map'],
]);

/**
 * A key holding null is an author who wrote the key; a key nobody wrote is silence. The two answer the
 * same VALUE and must not answer the same way about it.
 */
it('separates a key written empty from a key nobody wrote', function (): void {
    expect(ConfiguredKeyword::read(['nullable' => null], 'nullable', 'type-array', STYLES)->refused)->toBeTrue()
        ->and(ConfiguredKeyword::read([], 'nullable', 'type-array', STYLES)->refused)->toBeFalse();
});

/**
 * The reader who mistyped a keyword is exactly the reader who does not know what the alternatives
 * were, so the help spells the set out in the order the shipped configuration lists it.
 */
it('lists every accepted value, in order, as the one thing to do about a refusal', function (): void {
    $read = ConfiguredKeyword::read(['naming' => 'x-enumnames'], 'naming', 'names', ['names', 'none', 'x-enumNames', 'x-enum-varnames']);

    expect($read->help())->toBe('Write one of: "names", "none", "x-enumNames", "x-enum-varnames".');
});

/**
 * A value out of a file reaches a terminal and a CI log, so nothing in it may steer either. JSON
 * notation is what does the escaping here — the value is read back as it would be written, and an
 * escape sequence has no spelling in JSON that a terminal acts on.
 */
it('escapes a refused value that would recolour a terminal', function (): void {
    $refusal = ConfiguredKeyword::read(['nullable' => "anyof\e[31m"], 'nullable', 'type-array', STYLES)
        ->refusal('representation.nullable');

    expect($refusal)->toContain('\\u001b[31m')
        ->and($refusal)->not->toContain("\e");
});
