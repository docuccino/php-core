<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Schema\TypedExample;

/**
 * The one reading of an authored example literal. A docblock tag and a rule parameter both hold text,
 * so every JSON type a schema can declare needs a reading of that text — and every shape that has no
 * reading has to answer "nothing", never a coerced `0` or `false`, because an example is the part of a
 * document a consumer copies out and sends back.
 */
it('reads a literal as every type a schema can declare', function (string $type, string $text, mixed $expected): void {
    expect(TypedExample::of($text, $type))->toBe([$expected]);
})->with([
    // Booleans. The whole of filter_var's vocabulary, because a docblock is prose and an author who
    // wrote `yes` meant true — publishing `false` for it is the defect this replaced.
    'boolean true' => ['boolean', 'true', true],
    'boolean false' => ['boolean', 'false', false],
    'boolean 1' => ['boolean', '1', true],
    'boolean 0' => ['boolean', '0', false],
    'boolean yes' => ['boolean', 'yes', true],
    'boolean no' => ['boolean', 'no', false],
    'boolean on' => ['boolean', 'on', true],
    'boolean off' => ['boolean', 'off', false],
    'boolean cased' => ['boolean', 'TRUE', true],
    'boolean padded' => ['boolean', "  false\t", false],

    // Integers.
    'integer' => ['integer', '7', 7],
    'integer zero' => ['integer', '0', 0],
    'integer negative' => ['integer', '-7', -7],
    'integer signed' => ['integer', '+7', 7],
    'integer padded' => ['integer', ' 42 ', 42],

    // Numbers. An integral literal beside `number` still reads, since JSON Schema's number admits it.
    'number' => ['number', '0.25', 0.25],
    'number negative' => ['number', '-0.5', -0.5],
    'number integral' => ['number', '1', 1.0],
    'number exponent' => ['number', '1e3', 1000.0],

    // Arrays and objects, from their JSON literals.
    'array of strings' => ['array', '["listing.view", "listing.create"]', ['listing.view', 'listing.create']],
    'array of numbers' => ['array', '[1, 2]', [1, 2]],
    'array empty' => ['array', '[]', []],
    'array nested' => ['array', '[{"id": 1}]', [['id' => 1]]],
    'object' => ['object', '{"id": 1, "name": "Cog"}', ['id' => 1, 'name' => 'Cog']],
    'object nested' => ['object', '{"a": {"b": [1]}}', ['a' => ['b' => [1]]]],

    // Null.
    'null' => ['null', 'null', null],

    // A string keeps the author's text byte for byte — there is nothing to read it as, and trimming or
    // decoding it would change what they wrote.
    'string' => ['string', 'acme', 'acme'],
    'string that looks like a number' => ['string', '7', '7'],
    'string that looks like a boolean' => ['string', 'false', 'false'],
    'string that looks like JSON' => ['string', '{"id": 1}', '{"id": 1}'],
    'string keeping its own whitespace' => ['string', ' padded ', ' padded '],
]);

it('reads nothing at all where the text is not the type', function (string $type, string $text): void {
    // Every one of these used to publish something false, or would have: `(int) "n/a"` is 0, and
    // `"n/a" === 'true'` is false. Nothing is the only honest answer.
    expect(TypedExample::of($text, $type))->toBeNull();
})->with([
    'prose on a boolean' => ['boolean', 'n/a'],
    'a word on a boolean' => ['boolean', 'maybe'],
    'a number on a boolean' => ['boolean', '7'],
    'prose on an integer' => ['integer', 'n/a'],
    'a decimal on an integer' => ['integer', '1.5'],
    'a boolean on an integer' => ['integer', 'true'],
    'a number past PHP_INT_MAX on an integer' => ['integer', '99999999999999999999'],
    'prose on a number' => ['number', 'lots'],
    'prose on an array' => ['array', 'a list of permissions'],
    'a bare list on an array' => ['array', 'a, b'],
    'an object literal on an array' => ['array', '{"id": 1}'],
    'malformed JSON on an array' => ['array', '["a",'],
    'prose on an object' => ['object', 'the widget'],
    'an array literal on an object' => ['object', '[1, 2]'],
    'malformed JSON on an object' => ['object', '{"id":'],
    // `{}` and `[]` are one PHP array once decoded, so publishing it would contradict `type: object`.
    'an empty object literal on an object' => ['object', '{}'],
    'anything but null on null' => ['null', 'nothing'],
]);

it('reads a union most specific first, so the reading is a function of the type set', function (mixed $type, string $text, mixed $expected): void {
    // Never a function of the order the members were met: both spellings of the same set read alike.
    expect(TypedExample::of($text, $type))->toBe([$expected]);
})->with([
    'integer|null takes the number' => [['integer', 'null'], '7', 7],
    'null|integer takes the number' => [['null', 'integer'], '7', 7],
    'integer|null takes the null' => [['integer', 'null'], 'null', null],
    'null|integer takes the null' => [['null', 'integer'], 'null', null],
    'integer|string prefers the number' => [['integer', 'string'], '7', 7],
    'string|integer prefers the number' => [['string', 'integer'], '7', 7],
    'integer|string falls back to the text' => [['integer', 'string'], 'n/a', 'n/a'],
    'boolean|string prefers the boolean' => [['boolean', 'string'], 'false', false],
    'array|null takes the literal' => [['array', 'null'], '[1]', [1]],
    'a duplicated member changes nothing' => [['integer', 'integer'], '7', 7],
]);

it('reads nothing where no member of a union reads the text', function (): void {
    expect(TypedExample::of('n/a', ['integer', 'null']))->toBeNull();
});

it('leaves the text alone where the schema declares no type it knows', function (mixed $type): void {
    // Nothing a string would violate is stated, so the text stands — a `$ref` or an `anyOf` may well
    // accept it, and the example audit over the finished document is what holds that case to its schema.
    expect(TypedExample::of('as written', $type))->toBe(['as written']);
})->with([
    'no type at all' => [null],
    'an empty list of types' => [[]],
    'a type nobody knows' => ['widget'],
    'a list of types nobody knows' => [['widget', 'sprocket']],
    'a non-string type' => [7],
    'a list mixing a known type with junk' => [[7, false]],
]);

it('reads nothing from an empty literal, whatever the type', function (string $type): void {
    // A tag whose value is whitespace states nothing. The string branch is the exception: an author who
    // wrote spaces into a string example wrote spaces.
    expect(TypedExample::of('   ', $type))->toBeNull();
})->with(['boolean', 'integer', 'number', 'array', 'object', 'null']);

/*
 * The datasets above only prove the rows they list. This reads the reading table itself, so a type
 * added to it without a reading — or without a shape that fails — fails here rather than shipping with
 * the whole suite green.
 */
it('has a reading, and a refusal, for every type in its table', function (): void {
    /** @var list<string> $order */
    $order = (new ReflectionClass(TypedExample::class))->getReflectionConstant('ORDER')?->getValue();

    // One literal each way per type. Keeping this map beside the table is the point: adding a type to
    // ORDER without deciding both answers breaks the key comparison below.
    $reads = [
        'null' => ['null', null],
        'boolean' => ['true', true],
        'integer' => ['7', 7],
        'number' => ['0.25', 0.25],
        'array' => ['[1]', [1]],
        'object' => ['{"a": 1}', ['a' => 1]],
        'string' => ['anything at all', 'anything at all'],
    ];

    // `string` is the one type with no refusal: every text is a string, by definition.
    $refuses = [
        'null' => 'not null',
        'boolean' => 'n/a',
        'integer' => 'n/a',
        'number' => 'n/a',
        'array' => 'n/a',
        'object' => 'n/a',
    ];

    expect($order)->not->toBeEmpty()
        ->and(array_keys($reads))->toBe($order)
        ->and(array_keys($refuses))->toBe(array_values(array_diff($order, ['string'])));

    foreach ($reads as $type => [$text, $expected]) {
        expect(TypedExample::of($text, $type))->toBe([$expected], $type.' has no reading');
    }

    foreach ($refuses as $type => $text) {
        expect(TypedExample::of($text, $type))->toBeNull($type.' reads something it should refuse');
    }
});

it('names the property, the text and the declared type when it publishes nothing', function (): void {
    $diagnostic = TypedExample::untypable('App\Data\TeamData::$renewal_seats', 'n/a', 'integer');

    expect($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('docblock.example-untypable')
        ->and($diagnostic->message)->toBe(
            'The example on App\Data\TeamData::$renewal_seats ("n/a") does not read as integer, so none is published.',
        )
        // Guidance for the author belongs in the help, and it has to name something to do.
        ->and($diagnostic->help)->toContain('@example false')
        ->and($diagnostic->help)->toContain('@example 7');
});

it('spells a union and an unknown type in the message the same way it reads them', function (mixed $type, string $reads): void {
    expect(TypedExample::untypable('field "x"', 'n/a', $type)->message)->toContain('does not read as '.$reads);
})->with([
    'a single type' => ['boolean', 'boolean'],
    'a union' => [['integer', 'null'], 'integer/null'],
    'a union naming the same member twice' => [['integer', 'integer'], 'integer'],
    'no type it knows' => [null, 'a value this schema can carry'],
    'a type nobody knows' => ['widget', 'a value this schema can carry'],
]);
