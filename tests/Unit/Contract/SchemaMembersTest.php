<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ReachableDefs;
use Docuccino\Core\Contract\SchemaCheck;
use Docuccino\Core\Contract\SchemaMembers;
use Docuccino\Core\Draft\SchemaKeywords;
use Opis\JsonSchema\Exceptions\UnresolvedReferenceException;
use Opis\JsonSchema\Parsers\DefaultVocabulary;
use Opis\JsonSchema\Parsers\Drafts\Draft202012;
use Opis\JsonSchema\Validator;

/*
 * What each keyword of a schema holds, for every keyword the table answers and for ones it does not. The
 * document's own positions are read off its table rather than listed, so a keyword gaining one is a row
 * here without anybody writing it.
 */
dataset('what each keyword holds', function (): array {
    $rows = [];

    foreach ([
        SchemaKeywords::POSITION_SCHEMA => SchemaMembers::SCHEMA,
        SchemaKeywords::POSITION_SCHEMA_MAP => SchemaMembers::NAMES,
        SchemaKeywords::POSITION_SCHEMA_LIST => SchemaMembers::LIST,
        SchemaKeywords::POSITION_STRING_LIST_MAP => SchemaMembers::DATA,
    ] as $position => $holds) {
        foreach (SchemaKeywords::at($position) as $keyword) {
            $rows[$keyword] = [$keyword, $holds];
        }
    }

    // Anti-vacuity: a table that stopped being read would leave only the hand rows below.
    expect(count($rows))->toBeGreaterThan(20);

    return [
        ...$rows,
        'const' => ['const', SchemaMembers::DATA],
        'default' => ['default', SchemaMembers::DATA],
        'enum' => ['enum', SchemaMembers::DATA],
        'example' => ['example', SchemaMembers::DATA],
        'examples' => ['examples', SchemaMembers::DATA],
        'an extension' => ['x-anything', SchemaMembers::DATA],
        'dependencies' => ['dependencies', SchemaMembers::NAMES],
        '$slots' => ['$slots', SchemaMembers::NAMES],
        'a pragma\'s slots' => ['slots', SchemaMembers::NAMES],
        'a keyword holding no schema' => ['type', SchemaMembers::SCHEMA],
        'a keyword nothing defines' => ['nonsense', SchemaMembers::SCHEMA],
    ];
});

it('reads each keyword of a schema as holding what the keyword holds', function (string $keyword, string $holds): void {
    expect(SchemaMembers::member($keyword, SchemaMembers::SCHEMA))->toBe($holds)
        // A list of schemas written as an object is read the way a schema is.
        ->and(SchemaMembers::member($keyword, SchemaMembers::LIST))->toBe($holds);
})->with('what each keyword holds');

it('reads a name spelled like a keyword as a name holding a schema, and anything inside a value as the value', function (string $keyword): void {
    expect(SchemaMembers::member($keyword, SchemaMembers::NAMES))->toBe(SchemaMembers::SCHEMA)
        ->and(SchemaMembers::member($keyword, SchemaMembers::DATA))->toBe(SchemaMembers::DATA)
        ->and(SchemaMembers::isReference($keyword, SchemaMembers::NAMES))->toBeFalse()
        ->and(SchemaMembers::isReference($keyword, SchemaMembers::DATA))->toBeFalse();
})->with('what each keyword holds');

it('reads an item as a schema anywhere but inside a value', function (): void {
    expect(SchemaMembers::item(SchemaMembers::SCHEMA))->toBe(SchemaMembers::SCHEMA)
        ->and(SchemaMembers::item(SchemaMembers::NAMES))->toBe(SchemaMembers::SCHEMA)
        ->and(SchemaMembers::item(SchemaMembers::LIST))->toBe(SchemaMembers::SCHEMA)
        ->and(SchemaMembers::item(SchemaMembers::DATA))->toBe(SchemaMembers::DATA);
});

it('takes $ref for a reference only where the members are keywords', function (): void {
    expect(SchemaMembers::isReference('$ref', SchemaMembers::SCHEMA))->toBeTrue()
        ->and(SchemaMembers::isReference('$ref', SchemaMembers::LIST))->toBeTrue()
        ->and(SchemaMembers::isReference('$ref', SchemaMembers::NAMES))->toBeFalse()
        ->and(SchemaMembers::isReference('$ref', SchemaMembers::DATA))->toBeFalse()
        ->and(SchemaMembers::isReference('$dynamicRef', SchemaMembers::SCHEMA))->toBeFalse()
        // A property called `$ref` holds a schema like any other.
        ->and(SchemaMembers::member('$ref', SchemaMembers::NAMES))->toBe(SchemaMembers::SCHEMA)
        ->and(SchemaMembers::member('$ref', SchemaMembers::DATA))->toBe(SchemaMembers::DATA);
});

it('reads an empty array as the empty object exactly where an object belongs', function (): void {
    $keywords = SchemaKeywords::objectValued();

    expect(count($keywords))->toBeGreaterThan(10);

    foreach ($keywords as $keyword) {
        expect(SchemaMembers::emptyIsObject($keyword, SchemaMembers::SCHEMA))->toBeTrue();
    }

    expect(SchemaMembers::emptyIsObject('allOf', SchemaMembers::SCHEMA))->toBeFalse()
        ->and(SchemaMembers::emptyIsObject('type', SchemaMembers::SCHEMA))->toBeFalse()
        ->and(SchemaMembers::emptyIsObject('const', SchemaMembers::SCHEMA))->toBeFalse()
        ->and(SchemaMembers::emptyIsObject('x-anything', SchemaMembers::SCHEMA))->toBeFalse()
        // An item of a schema is no keyword's value: a draft-07 tuple, or a `type` list.
        ->and(SchemaMembers::emptyIsObject(null, SchemaMembers::SCHEMA))->toBeFalse()
        ->and(SchemaMembers::emptyIsObject('anything', SchemaMembers::NAMES))->toBeTrue()
        ->and(SchemaMembers::emptyIsObject(null, SchemaMembers::NAMES))->toBeFalse()
        ->and(SchemaMembers::emptyIsObject(null, SchemaMembers::LIST))->toBeTrue()
        ->and(SchemaMembers::emptyIsObject('anything', SchemaMembers::LIST))->toBeFalse()
        ->and(SchemaMembers::emptyIsObject('properties', SchemaMembers::DATA))->toBeFalse()
        ->and(SchemaMembers::emptyIsObject(null, SchemaMembers::DATA))->toBeFalse();
});

/*
 * The guard the walks answer to. Whether the validator follows a reference is stated by the validator
 * itself, each row run through it with nothing of ours in the way, and every walk here must reach at
 * least that far: one that stops short is how a property called `default` came to be refused. None may
 * reach into a value, which the validator compares as it stands.
 */
dataset('where the validator follows a reference', validatorReferencePositions());

it('reaches every reference the validator follows, and none inside a value', function (mixed $schema, mixed $instance, ?bool $follows): void {
    $followed = false;

    try {
        (new Validator)->validate($instance, atReferencePosition($schema, '#/$defs/Target'));
    } catch (UnresolvedReferenceException) {
        $followed = true;
    }

    expect($followed)->toBe($follows === true);

    // A walk may reach where the validator reads no schema — a reference nothing follows changes no
    // answer — so a null row says nothing about the walk.
    if ($follows !== null) {
        expect(in_array('Target', ReachableDefs::of(atReferencePosition($schema, '#/$defs/Target')) ?? [], true))->toBe($follows);
    }
})->with('where the validator follows a reference');

it('points every reference the validator follows where it resolves, and leaves one inside a value as written', function (mixed $schema, mixed $instance, ?bool $follows): void {
    $recorder = recordingSchemaValidators();
    $subject = json_decode((string) json_encode(atReferencePosition($schema, '#/components/schemas/Leaf')), true);
    $check = new SchemaCheck(reachableDefsDocument(is_array($subject) ? $subject : []), $recorder->factory());

    $check->check($instance, reachableDefsSubject(), 'the body');

    expect($recorder->roots)->toHaveCount(1);

    $root = (string) json_encode($recorder->roots[0], JSON_UNESCAPED_SLASHES);
    $shared = ! property_exists($recorder->roots[0], '$defs');

    if ($follows === true) {
        expect($root)->not->toContain('"#/components/schemas/Leaf"')
            ->and($root)->toContain($shared ? '.json#/$defs/Leaf"' : '"#/$defs/Leaf"');

        if ($shared) {
            expect($root)->not->toContain('"#/$defs/Leaf"');
        }
    }

    if ($follows === false) {
        expect($root)->toContain('"#/components/schemas/Leaf"')
            ->and($root)->not->toContain('#/$defs/Leaf');
    }
})->with('where the validator follows a reference');

it('says where a reference goes for every keyword the validator reads', function (): void {
    $draft = new Draft202012;
    $vocabulary = new DefaultVocabulary;
    $parsers = [
        ...(new ReflectionMethod($draft, 'getKeywordParsers'))->invoke($draft),
        (new ReflectionMethod($draft, 'getRefKeywordParser'))->invoke($draft),
        ...$vocabulary->keywords(),
        ...$vocabulary->keywordValidators(),
    ];

    $read = array_map(static fn (object $parser): mixed => (new ReflectionProperty($parser, 'keyword'))->getValue($parser), $parsers);

    // Anti-vacuity: forty-four today, and reading none would mean the grammar moved, not that it shrank.
    expect(count($read))->toBeGreaterThan(40);

    $stated = [
        ...array_keys(validatorReferencePositions()),
        // The keywords that hold no schema at all: bounds, names, and a filter's own arguments.
        'type', 'format', 'minLength', 'maxLength', 'pattern', 'contentEncoding', 'contentMediaType',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minItems', 'maxItems',
        'uniqueItems', 'minProperties', 'maxProperties', 'required', 'dependentRequired', '$filters',
    ];

    $positioned = [
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA),
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_MAP),
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST),
    ];

    expect(array_values(array_diff($read, $stated)))->toBe([])
        // …and every place the document's own table puts a schema is a row the validator was asked about.
        ->and(array_values(array_diff($positioned, array_keys(validatorReferencePositions()))))->toBe([]);
});
