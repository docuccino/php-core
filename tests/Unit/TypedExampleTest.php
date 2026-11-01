<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\SchemaCheck;
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
    // Nothing a string would violate is stated, so the text stands, and the example audit over the
    // finished document is what holds that case to its schema.
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
 * The one exception to "the text stands as written" where no type is stated — a `$ref` to a component
 * not registered yet, say, which may well be an enum: `"draft"` beside one published the six characters
 * `"draft"` against an enum of `draft`, an example the document's own lint then reported as a mismatch,
 * correctly. Every reading such a member could accept is the string the literal quotes and never the
 * quotes, so the quotes are the author writing JSON.
 */
it('reads a quoted JSON string literal as the string it quotes where no type is stated', function (mixed $type, string $text, string $expected): void {
    expect(TypedExample::of($text, $type))->toBe([$expected]);
})->with([
    'no type at all' => [null, '"standard"', 'standard'],
    'an empty list of types' => [[], '"hybrid"', 'hybrid'],
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

it('says on a validation field that a generated example may stand in for the refused one', function (): void {
    // A field's own rules earn it an example where they pin a value, so on a field "none is published"
    // can be false: the refused text is what is not published. Same code and help as everywhere else.
    $field = TypedExample::unreadableOnField('status', 'active', ['type' => 'string', 'enum' => ['draft', 'published']]);
    $docblock = TypedExample::unreadable('field "status"', 'active', ['type' => 'string', 'enum' => ['draft', 'published']]);

    expect($field->message)->toBe('The example on field "status" ("active") does not read as "draft"/"published", so it is not published; where the field\'s rules pin a value, an example generated from them is published instead.')
        ->and([$field->severity, $field->code, $field->help])->toBe([$docblock->severity, $docblock->code, $docblock->help]);
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

/*
 * An example is a value for the schema as a whole. A `$ref`, an `anyOf` or a `oneOf` states no `type` of
 * its own, and reading against that absent `type` published `@example null` beside a nullable enum as the
 * string `"null"` — rejected by every branch, the null one included. Each row here reads against every
 * branch the schema admits; {@see typedExampleDocument()} is what holds the answer to the schema.
 */
function typedExampleComponents(): array
{
    return [
        'Status' => ['type' => 'string', 'enum' => ['draft', 'published']],
        'Priority' => ['type' => 'integer', 'enum' => [1, 2]],
        'Loop' => ['$ref' => '#/components/schemas/Loop'],
    ];
}

/** A document carrying `$schema` as a component of its own, for the validator to hold a reading to. */
function typedExampleDocument(array $schema): ContractIndex
{
    return ContractIndex::fromArray([
        'openapi' => '3.2.0',
        'info' => ['title' => 't', 'version' => '1'],
        'paths' => [],
        'components' => ['schemas' => [...typedExampleComponents(), 'Subject' => $schema]],
    ]);
}

dataset('readings against a whole schema', [
    // The reported shape: a nullable enum, as the type chain publishes it.
    'null beside a nullable $ref' => [['anyOf' => [['$ref' => '#/components/schemas/Status'], ['type' => 'null']]], 'null', null],
    'a member beside a nullable $ref' => [['anyOf' => [['$ref' => '#/components/schemas/Status'], ['type' => 'null']]], 'draft', 'draft'],
    'a quoted member beside a nullable $ref' => [['anyOf' => [['$ref' => '#/components/schemas/Status'], ['type' => 'null']]], '"draft"', 'draft'],
    // A `$ref` to an integer enum lists numbers, so the number is the member.
    'a number beside an integer $ref' => [['$ref' => '#/components/schemas/Priority'], '2', 2],
    'null beside a nullable integer $ref' => [['oneOf' => [['$ref' => '#/components/schemas/Priority'], ['type' => 'null']]], 'null', null],
    // The `anyof` nullable policy's spelling of `integer|null` and `boolean|null`.
    'a number beside an anyOf of integer and null' => [['anyOf' => [['type' => 'integer'], ['type' => 'null']]], '7', 7],
    'null beside an anyOf of integer and null' => [['anyOf' => [['type' => 'integer'], ['type' => 'null']]], 'null', null],
    'a boolean beside an anyOf of boolean and null' => [['anyOf' => [['type' => 'boolean'], ['type' => 'null']]], 'true', true],
    'a boolean beside a oneOf of boolean and string' => [['oneOf' => [['type' => 'boolean'], ['type' => 'string']]], 'false', false],
    'a number beside a type list' => [['type' => ['number', 'null']], '0.5', 0.5],
    // Where several readings are accepted the most specific type wins, so `7` is the number beside a
    // union that would take the string too — but a value the enum does not list is not a reading of it.
    'a member number beside an integer enum or a string' => [['anyOf' => [['$ref' => '#/components/schemas/Priority'], ['type' => 'string']]], '2', 2],
    'a non-member number beside an integer enum or a string' => [['anyOf' => [['$ref' => '#/components/schemas/Priority'], ['type' => 'string']]], '7', '7'],
    // An inline value list is read the same way as one behind a `$ref`.
    'a quoted member of an inline enum' => [['type' => 'string', 'enum' => ['draft', 'published']], '"draft"', 'draft'],
    'a constant' => [['type' => 'integer', 'const' => 3], '3', 3],
    'a value list with no type' => [['enum' => [1, 'one']], '1', 1],
    'a string in a value list with no type' => [['enum' => [1, 'one']], 'one', 'one'],
    'an integral literal in a number enum' => [['type' => 'number', 'enum' => [1, 2.5]], '1', 1.0],
]);

it('reads a literal against every branch a schema admits', function (array $schema, string $text, mixed $expected): void {
    expect(TypedExample::against($text, $schema, typedExampleComponents()))->toBe([$expected]);
})->with('readings against a whole schema');

it('publishes nothing its schema rejects', function (array $schema, string $text, mixed $expected): void {
    // The rule stated by the validator the example audit runs, not by the reader: what the reader
    // answers is a value the schema accepts, `$ref`s resolved.
    $read = TypedExample::against($text, $schema, typedExampleComponents());

    expect($read)->not->toBeNull()
        ->and((new SchemaCheck(typedExampleDocument($schema)))->check($read[0] ?? null, ['components', 'schemas', 'Subject'], 'the example'))
        ->toBe([]);
})->with('readings against a whole schema');

it('reads the same whichever order a union lists its branches in', function (array $schema, string $text, mixed $expected): void {
    foreach (['anyOf', 'oneOf'] as $keyword) {
        if (isset($schema[$keyword])) {
            $schema[$keyword] = array_reverse($schema[$keyword]);
        }
    }

    expect(TypedExample::against($text, $schema, typedExampleComponents()))->toBe([$expected]);
})->with('readings against a whole schema');

it('reads null beside 3.0\'s nullable', function (array $schema, string $text, mixed $expected): void {
    // `nullable: true` admits null beside a type or around a reference. The 2020-12 validator above does
    // not read it, so these rows are held to the reading alone.
    expect(TypedExample::against($text, $schema, typedExampleComponents()))->toBe([$expected]);
})->with([
    'a nullable integer' => [['type' => 'integer', 'nullable' => true], 'null', null],
    'a nullable integer, read as one' => [['type' => 'integer', 'nullable' => true], '7', 7],
    'a nullable reference' => [['allOf' => [['$ref' => '#/components/schemas/Priority']], 'nullable' => true], 'null', null],
    'a nullable reference, read as a member' => [['allOf' => [['$ref' => '#/components/schemas/Priority']], 'nullable' => true], '1', 1],
]);

it('reads nothing where no reading is a value the schema accepts', function (array $schema, string $text): void {
    $components = typedExampleComponents();

    expect(TypedExample::against($text, $schema, $components))->toBeNull();

    // Nor could anything have been published: the text as written, and the value it decodes to where it
    // is JSON, are both rejected by the schema.
    $check = new SchemaCheck(typedExampleDocument($schema));
    $decoded = json_decode($text, true);
    $json = json_last_error() === JSON_ERROR_NONE;

    expect($check->check($text, ['components', 'schemas', 'Subject'], 'the example'))->not->toBe([])
        ->and(! $json || $check->check($decoded, ['components', 'schemas', 'Subject'], 'the example') !== [])
        ->toBeTrue();
})->with([
    'a word no case is' => [['anyOf' => [['$ref' => '#/components/schemas/Status'], ['type' => 'null']]], 'active'],
    'a number no case is' => [['$ref' => '#/components/schemas/Priority'], '9'],
    'a word beside an integer enum' => [['$ref' => '#/components/schemas/Priority'], 'high'],
    'another constant' => [['type' => 'integer', 'const' => 3], '4'],
    // The null type alone does not make null a member: the value list refuses it.
    'null beside a value list that leaves it out' => [['type' => ['string', 'null'], 'enum' => ['a', 'b']], 'null'],
]);

it('leaves the text as written where a branch states nothing it can read', function (array $schema): void {
    // What such a branch accepts is unknowable here, and the example audit over the finished document is
    // what holds it — the validator above cannot, since the component is not there to resolve.
    expect(TypedExample::against('as written', $schema, typedExampleComponents()))->toBe(['as written']);
})->with([
    'an unregistered $ref' => [['$ref' => '#/components/schemas/Missing']],
    'a component that names only itself' => [['$ref' => '#/components/schemas/Loop']],
    'an allOf of several' => [['allOf' => [['$ref' => '#/components/schemas/Status'], ['description' => 'x']]]],
    'an empty schema' => [[]],
    'an unregistered $ref beside a null branch' => [['anyOf' => [['$ref' => '#/components/schemas/Missing'], ['type' => 'null']]]],
]);

it('still reads null beside an unregistered $ref, where the null branch takes it', function (): void {
    $schema = ['anyOf' => [['$ref' => '#/components/schemas/Missing'], ['type' => 'null']]];

    expect(TypedExample::against('null', $schema, typedExampleComponents()))->toBe([null]);
});

it('names the values a refused example could have been', function (array $schema, string $reads): void {
    $diagnostic = TypedExample::unreadable('field "x"', 'n/a', $schema, typedExampleComponents());

    expect($diagnostic->code)->toBe('docblock.example-untypable')
        ->and($diagnostic->message)->toBe('The example on field "x" ("n/a") does not read as '.$reads.', so none is published.');
})->with([
    'a nullable enum' => [['anyOf' => [['$ref' => '#/components/schemas/Status'], ['type' => 'null']]], '"draft"/"published"/null'],
    'an integer enum' => [['$ref' => '#/components/schemas/Priority'], '1/2'],
    'a type union' => [['anyOf' => [['type' => 'integer'], ['type' => 'boolean']]], 'integer/boolean'],
    'a long list, cut short' => [['enum' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']], '"a"/"b"/"c"/"d"/"e"/"f" (and 2 more)'],
]);

it('reads a component reached by many paths once, and one reached from itself as open', function (): void {
    // Each level is a union of the next level twice, so the paths to the bottom double at every level.
    // Read once per path, 24 levels are 2^24 readings of the bottom; read once per component, 25.
    $components = ['Level24' => ['type' => 'integer', 'enum' => [1]]];

    for ($level = 0; $level < 24; $level++) {
        $next = ['$ref' => '#/components/schemas/Level'.($level + 1)];
        $components['Level'.$level] = ['anyOf' => [$next, $next]];
    }

    $top = ['$ref' => '#/components/schemas/Level0'];

    expect(TypedExample::against('1', $top, $components))->toBe([1])
        ->and(TypedExample::against('2', $top, $components))->toBeNull()
        ->and(TypedExample::unreadable('field "x"', '2', $top, $components)->message)->toContain('does not read as 1,');

    // The bottom naming the top again is a component that refers to itself, which states nothing.
    $components['Level24'] = ['anyOf' => [['type' => 'integer', 'enum' => [1]], $top]];

    expect(TypedExample::against('2', $top, $components))->toBe(['2']);
});
