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
    'object padded' => ['object', "  {\"id\": 1}\t", ['id' => 1]],

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
    'an empty array literal on an object' => ['object', '[]'],
    'malformed JSON on an object' => ['object', '{"id":'],
    'anything but null on null' => ['null', 'nothing'],
]);

/*
 * `{}` and `[]` decode to the same PHP array, and an empty object literal was refused for it — so a
 * valid example was dropped and a diagnostic said `{}` "does not read as object". Both halves matter:
 * it has to READ, and what it reads has to still be an object by the time something publishes it.
 * The four rows below are the whole empty/non-empty × object/array matrix.
 */
it('reads every empty and non-empty literal against every collection type it can sit beside', function (
    string $type,
    string $text,
    string $expected,
): void {
    $read = TypedExample::of($text, $type);

    if ($expected === 'refused') {
        expect($read)->toBeNull();

        return;
    }

    expect($read)->not->toBeNull();

    // The published shape, spelled as the JSON it will become — the only thing a consumer ever sees, and
    // where `[]` for `{}` is the defect. `json_encode` is the same reading `CanonicalJsonSerializer`
    // makes: an empty array writes `[]`, an empty `stdClass` writes `{}`.
    expect(json_encode($read[0]))->toBe($expected);
})->with([
    'an empty object literal on an object publishes an object' => ['object', '{}', '{}'],
    'a non-empty object literal on an object publishes an object' => ['object', '{"a": 1}', '{"a":1}'],
    'an empty array literal on an array publishes an array' => ['array', '[]', '[]'],
    'a non-empty array literal on an array publishes an array' => ['array', '[1]', '[1]'],

    // Crossed over, both refuse rather than publishing the other collection kind.
    'an empty object literal on an array is refused' => ['array', '{}', 'refused'],
    'a non-empty object literal on an array is refused' => ['array', '{"a": 1}', 'refused'],
    'an empty array literal on an object is refused' => ['object', '[]', 'refused'],
    'a non-empty array literal on an object is refused' => ['object', '[1]', 'refused'],
]);

it('keeps an empty object nested inside a literal an object too', function (): void {
    // The reading is recursive or it only fixes the top level: a map whose VALUE is `{}` publishes an
    // empty object there as well, and an empty list beside it stays a list.
    $read = TypedExample::of('{"settings": {}, "tags": [], "name": "cog"}', 'object');

    expect($read)->not->toBeNull()
        ->and(json_encode($read[0]))->toBe('{"settings":{},"tags":[],"name":"cog"}');

    // And inside a list, which decodes down the other branch.
    $list = TypedExample::of('[{}, [], {"a": {}}]', 'array');

    expect($list)->not->toBeNull()
        ->and(json_encode($list[0]))->toBe('[{},[],{"a":{}}]');
});

it('reads an empty object literal where a union admits an object', function (): void {
    // A nullable free-form map is `['object', 'null']`, and `{}` there is an object rather than the null.
    $read = TypedExample::of('{}', ['object', 'null']);

    expect($read)->not->toBeNull()
        ->and(json_encode($read[0]))->toBe('{}');
});

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

/*
 * The one exception to "the text stands as written" where no type is stated. A member whose type is
 * carried by a `$ref` — an enum component, alone or inside a nullable `anyOf` — is a real constraint, and
 * `"draft"` beside it published the six characters `"draft"` against an enum of `draft`: an example the
 * document's own lint then reported as a mismatch, correctly. Every reading such a member could accept is
 * the string the literal quotes and never the quotes, so the quotes are the author writing JSON.
 */
it('reads a quoted JSON string literal as the string it quotes where no type is stated', function (mixed $type, string $text, string $expected): void {
    expect(TypedExample::of($text, $type))->toBe([$expected]);
})->with([
    'a $ref to a string-backed enum' => [null, '"standard"', 'standard'],
    'a nullable anyOf around one' => [null, '"hybrid"', 'hybrid'],
    'padded, since whitespace around a docblock tag is layout' => [null, "  \"draft\"\t", 'draft'],
    'a value carrying the characters JSON escapes' => [null, '"a \"quoted\" word"', 'a "quoted" word'],
    'an escape sequence, read as the character it names' => [null, '"a\\/b"', 'a/b'],
    'an empty string, which is a value a schema can carry' => [null, '""', ''],
    'a type nobody knows, which states nothing either' => ['widget', '"standard"', 'standard'],
]);

it('leaves everything that is not a complete quoted string exactly as written', function (string $text): void {
    // The reading is JSON's, not "strip the outer quotes": a half-quoted or malformed literal is text an
    // author wrote, and guessing at it would publish something they did not.
    expect(TypedExample::of($text, null))->toBe([$text]);
})->with([
    'unquoted' => ['standard'],
    'opening quote only' => ['"standard'],
    'closing quote only' => ['standard"'],
    'a lone quote' => ['"'],
    'two literals, not one' => ['"a" "b"'],
    'an unterminated escape' => ['"a\\"'],
    // A number, an object and a list all decode, but none of them decodes to a STRING — and what a `$ref`
    // would want of them is unknowable, so they stay as written rather than change type on a guess.
    'a number' => ['7'],
    'a boolean' => ['true'],
    'null' => ['null'],
    'an object literal' => ['{"id": 1}'],
    'a list literal' => ['["a"]'],
]);

it('keeps publishing a string type byte for byte, quotes and all', function (mixed $type): void {
    // The other half of the rule, and the reason it is scoped to a stated-no-type schema: where the
    // schema says `string` and nothing narrows which string, the quotes may be part of the value, so the
    // author's bytes stand. This is the row that must not move.
    expect(TypedExample::of('"hello"', $type))->toBe(['"hello"']);
})->with([
    'a plain string' => ['string'],
    'a nullable string' => [['string', 'null']],
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

it('publishes an id-keyed object literal as the object it was written as', function (string $text, string $expected): void {
    // A PHP array cannot represent an object whose member names re-key to a `0..n-1` run: those come
    // back out as a JSON LIST, and an id-keyed map published as `["Widget","Cog"]` beside `type: object`
    // is the same lie `{}` as `[]` was. Every other numeric spelling an array carries perfectly well.
    $read = TypedExample::of($text, 'object');

    expect($read)->not->toBeNull()
        ->and(json_encode($read[0]))->toBe($expected);
})->with([
    'zero-based and contiguous, the one shape an array cannot carry' => [
        '{"0": "Widget", "1": "Cog"}',
        '{"0":"Widget","1":"Cog"}',
    ],
    'one-based, which an array carries as a map' => [
        '{"1": "Widget", "2": "Cog"}',
        '{"1":"Widget","2":"Cog"}',
    ],
    'sparse' => ['{"3": "Widget", "9": "Cog"}', '{"3":"Widget","9":"Cog"}'],
    'nested inside a named member' => ['{"by_id": {"0": "Widget"}}', '{"by_id":{"0":"Widget"}}'],
]);

it('still reads a genuine list as a list beside an id-keyed object', function (): void {
    // The other half: `type: array` must not start taking objects just because they look like lists.
    expect(TypedExample::of('{"0": "a"}', 'array'))->toBeNull()
        ->and(json_encode(TypedExample::of('["a"]', 'array')[0] ?? null))->toBe('["a"]');
});
