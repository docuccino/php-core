<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * What `symfony/yaml` does with the spellings an author reaches for, stated as hand-written types and
 * values and NOT derived from anything in this package.
 *
 * That independence is the point. Every other guard over the configuration reader asks the reader what
 * it produced, so all of them move together when the parser underneath changes its mind — and a
 * changed reading is a changed `configHash`, which is a fragment-cache key. This file is the one that
 * fails FIRST on a dependency bump, and it fails saying which spelling moved and to what.
 *
 * It is also where the reader's parse flags are justified. A flag is a claim about the parser, so it
 * belongs beside the parser's other measured behaviour rather than in a docblock nobody re-checks.
 */
it('reads each hostile spelling as exactly one type and value', function (string $spelling, string $type, mixed $value): void {
    $parsed = Yaml::parse('k: '.$spelling);

    expect($parsed)->toBeArray();
    expect(get_debug_type($parsed['k']))->toBe($type)
        ->and($parsed['k'])->toBe($value);
})->with([
    // The Norway problem, and it does not land where the name suggests: these are TEXT, not booleans.
    // Which is worse, because a reader willing to convert reads the non-empty string "no" as TRUE.
    'no' => ['no', 'string', 'no'],
    'off' => ['off', 'string', 'off'],
    'yes' => ['yes', 'string', 'yes'],
    'on' => ['on', 'string', 'on'],
    'NO' => ['NO', 'string', 'NO'],
    'Off' => ['Off', 'string', 'Off'],
    'y' => ['y', 'string', 'y'],
    'n' => ['n', 'string', 'n'],
    'Y' => ['Y', 'string', 'Y'],
    'N' => ['N', 'string', 'N'],

    // The two words that ARE booleans, in both spellings that work.
    'true' => ['true', 'bool', true],
    'false' => ['false', 'bool', false],
    'True' => ['True', 'bool', true],

    // A version number loses a trailing zero to the float it becomes, and gets it back the moment a
    // third segment makes the whole thing unparseable as a number.
    '1.10' => ['1.10', 'float', 1.1],
    '1.0' => ['1.0', 'float', 1.0],
    '0.14.0' => ['0.14.0', 'string', '0.14.0'],

    // A date is an integer of seconds, so a document's `version: 2024-01-15` publishes 1705276800.
    '2024-01-15' => ['2024-01-15', 'int', 1705276800],

    // Alternative integer notations, all of which resolve to a different number than they look like.
    '0x1A' => ['0x1A', 'int', 26],
    '0o17' => ['0o17', 'int', 15],
    '1_000' => ['1_000', 'int', 1000],

    // And the two that look like notations and are not: a leading zero is NOT octal here, so a file
    // mode stays text — which is the spelling somebody reaches for first.
    '0777' => ['0777', 'string', '0777'],
    '08' => ['08', 'string', '08'],

    // Past PHP_INT_MAX the value stops being an integer rather than wrapping or losing precision.
    'over PHP_INT_MAX' => ['9223372036854775808', 'string', '9223372036854775808'],

    // Three spellings of one absence.
    'tilde' => ['~', 'null', null],
    'null' => ['null', 'null', null],
    'blank' => ['', 'null', null],
]);

/**
 * The spellings whose reading the PARSER chooses, and which choice it makes is not stable across the
 * versions of it this package allows. Measured one patch release apart, inside the range `php/core`
 * declares:
 *
 * | spelling | 7.4.12 | 7.4.15 |
 * |---|---|---|
 * | `+1`, `+0`, `+1_000` | float | int |
 * | `.nan`, `.NaN` | INF | NAN |
 *
 * So these rows say what is true across the whole range rather than what this lockfile happens to
 * resolve — a number, and which KIND of number is the parser's business. What must not vary is what
 * the reader does with it, and that is asserted where the reader is, not here.
 */
it('reads a signed integer as some number, without promising which kind', function (string $spelling, int|float $number): void {
    $parsed = Yaml::parse('k: '.$spelling);

    expect($parsed)->toBeArray();

    // Deliberately not toBe(): pinning `int` here is what failed CI on a --prefer-lowest leg, and
    // pinning `float` would fail on the lockfile. Both readings are correct; the number is the fact.
    expect($parsed['k'])->toBeNumeric()
        ->and((float) $parsed['k'])->toBe((float) $number);
})->with([
    '+1' => ['+1', 1],
    '+0' => ['+0', 0],
    '+1_000' => ['+1_000', 1000],
]);

it('reads a non-finite spelling as some non-finite float, without promising which', function (string $spelling): void {
    // The worse half of the same class, and the reason a guard shaped around TYPES would have missed
    // it: `.nan` is a float on both versions and a different VALUE on each. Nothing here can assert
    // which; what the reader must do with either is asserted where the reader is.
    $parsed = Yaml::parse('k: '.$spelling);

    expect($parsed)->toBeArray()
        ->and($parsed['k'])->toBeFloat()
        ->and(is_finite($parsed['k']))->toBeFalse();
})->with(['.nan', '.NaN', '.inf', '-.inf', '.Inf']);

it('lists enough spellings to still be a corpus', function (): void {
    // A dataset proves the rows it lists and nothing else, so a corpus quietly emptied out would pass
    // forever. The floor is well under the count above; it is here to notice a deletion, not to pin one.
    $rows = 0;
    foreach ((array) glob(__DIR__.'/YamlScalarGrammarTest.php') as $file) {
        $rows = preg_match_all("/^    '[^']+' => \['/m", (string) file_get_contents((string) $file));
    }

    expect($rows)->toBeGreaterThanOrEqual(25);
});

it('parses an empty document to null rather than to an empty map', function (): void {
    // The reason the reader cannot write `(array) Yaml::parse(…)`: that cast turns this into `[]`, and
    // a build then runs on every default while the author's file looks applied.
    expect(Yaml::parse(''))->toBeNull()
        ->and(Yaml::parse("\n  \n"))->toBeNull()
        ->and(Yaml::parse("# only a comment\n"))->toBeNull();
});

it('parses a blank collection to null and a written-out empty one to an array', function (): void {
    // Two spellings of one intent that are NOT one value, so they hash differently. The reader does not
    // normalise them — see the note on `map()` — so the shipped template writes the empty one out.
    expect(Yaml::parse('servers:'))->toBe(['servers' => null])
        ->and(Yaml::parse('servers: []'))->toBe(['servers' => []])
        ->and(Yaml::parse('servers: {}'))->toBe(['servers' => []]);
});

it('refuses the shapes a configuration file must not have', function (string $yaml, string $message): void {
    expect(static fn (): mixed => Yaml::parse($yaml))
        ->toThrow(ParseException::class, $message);
})->with([
    // Stricter than PHP, which silently keeps the last of two identical keys.
    'duplicate key' => ["a: 1\na: 2\n", 'Duplicate key "a" detected at line 2'],
    'tab indentation' => ["a:\n\tb: 1\n", 'A YAML file cannot contain tabs as indentation at line 2'],
    'unclosed inline' => ["a: [1, 2\n", 'Malformed inline YAML string at line 2'],
    'second document' => ["a: 1\n---\nb: 2\n", 'Multiple documents are not supported at line 2'],
    'unknown tag' => ["a: !mine 1\n", 'Tags support is not enabled'],
    'missing anchor' => ["b: *nope\n", 'Reference "nope" does not exist at line 1'],
    'evaluable key' => ["true: b\n", 'Non-string keys are not supported'],
    'numeric key' => ["1.5: b\n", 'Numeric keys are not supported'],
]);

it('loses a php tag to null unless the reader asks to be told', function (string $tagged, string $message): void {
    // The measurement the reader's one parse flag exists for. Left to the default, these three parse to
    // NULL with nothing said — indistinguishable from a key the author wrote as null, which is a value
    // silently gone from a hash that keys the fragment cache.
    expect(Yaml::parse('a: '.$tagged))->toBe(['a' => null]);

    expect(static fn (): mixed => Yaml::parse('a: '.$tagged, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE))
        ->toThrow(ParseException::class, $message);
})->with([
    'const' => ['!php/const PHP_INT_MAX', 'could not be parsed as a constant'],
    'enum' => ['!php/enum Foo::Bar', 'could not be parsed as an enum'],
    'object' => ['!php/object O:8:"stdClass":0:{}', 'Object support when parsing a YAML file has been disabled'],
]);

it('changes nothing else under the reader flags it does pass', function (string $spelling): void {
    // The other half of the flag's justification: it turns three silent nulls into errors and leaves
    // every spelling in the corpus above reading exactly as it did.
    expect(Yaml::parse('k: '.$spelling, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE))
        ->toBe(Yaml::parse('k: '.$spelling));
})->with(['no', 'off', 'yes', 'on', '1.10', '1.0', '0.14.0', '2024-01-15', '0x1A', '0o17', '1_000', '0777', '08', '~', '+1', '9223372036854775808', 'true', 'false']);

it('passes the one flag it was measured to need, and no other', function (): void {
    // Written out by hand rather than read off the class, so this states the decision instead of
    // agreeing with whatever the class currently does. The two rows above are what justifies it: the
    // flag turns three silent nulls into errors and leaves the corpus alone. Adding PARSE_DATETIME,
    // PARSE_CONSTANT or PARSE_CUSTOM_TAGS here would each turn a refusal back into a value.
    expect(ConfigFile::FLAGS)->toBe(Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
});

it('reads a byte-order mark as part of the first key', function (): void {
    // Why the reader strips it: left in place the mark lands INSIDE the key's name, so `documents:`
    // becomes a key nothing reads and the file goes quietly unapplied. No exception, no diagnostic —
    // the parse succeeds and means something else.
    $parsed = Yaml::parse("\u{FEFF}documents: {}\n");

    expect($parsed)->toBeArray()
        ->and(array_keys($parsed))->toBe(["\u{FEFF}documents"])
        ->and($parsed)->not->toHaveKey('documents');
});

it('reads the same values whichever line ending the file was checked out with', function (): void {
    // Measured, not assumed: the parser folds CRLF and lone CR itself, block scalars included. So the
    // reader normalises nothing, and this is the guard that fires if that ever stops being true —
    // rather than a normalising pass that would hide the change.
    $lf = "a: 1\nb: hello\nc: |\n  one\n  two\n";

    expect(Yaml::parse(str_replace("\n", "\r\n", $lf)))->toBe(Yaml::parse($lf))
        ->and(Yaml::parse(str_replace("\n", "\r", $lf)))->toBe(Yaml::parse($lf))
        ->and(Yaml::parse($lf)['b'])->toBe('hello');
});
