<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\SchemaComparator;
use Docuccino\Core\Document\UirDocument;

/*
 * A union whose branches include a schema admits everything that schema admits, and more. So `X` becoming
 * `anyOf: [X, {type: null}]`, which is how OpenAPI 3.1 makes a `$ref` nullable, only widens what the
 * position admits: a writer stays valid, and a reader may now meet a null. That is the finding
 * `nullable: true` gets, safe on a request and breaking on a response, never a union landing on an
 * unconstrained schema, which is breaking on both.
 */

/**
 * A document whose response and request both carry `$schema` as the `owner` property.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function unionBesideDocument(string $openapi, array $schema): array
{
    $body = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['owner' => $schema]]]]];

    return [
        'openapi' => $openapi,
        'info' => ['title' => 'Pets', 'version' => '1'],
        'paths' => ['/pets' => [
            'get' => ['responses' => ['200' => ['description' => 'OK'] + $body]],
            'put' => ['requestBody' => $body, 'responses' => ['204' => ['description' => 'Saved']]],
        ]],
        'components' => ['schemas' => [
            'Owner' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            // A component that is another under a description of its own.
            'Guardian' => ['allOf' => [['$ref' => '#/components/schemas/Owner']], 'description' => 'Who answers for the pet'],
            // A name resolving only to itself, which describes no value at all.
            'Loop' => ['$ref' => '#/components/schemas/Loop'],
        ]],
    ];
}

/**
 * @param  array<string, mixed>  $old
 * @param  array<string, mixed>  $new
 */
function unionBesideDiff(array $old, array $new, string $oldVersion = '3.1.1', ?string $newVersion = null): Changeset
{
    $read = static fn (string $openapi, array $schema): UirDocument => UirDocument::fromArray(unionBesideDocument($openapi, $schema));

    return (new DocumentDiffer)->diff($read($oldVersion, $old), $read($newVersion ?? $oldVersion, $new));
}

/** @return list<string> each change as its operation, verdict, code and property-relative pointer, sorted */
function unionBesideChanges(Changeset $changeset): array
{
    $changes = array_map(
        static fn ($change): string => strtok($change->path, ' ').' '.($change->breaking ? 'breaking ' : '').$change->code.' '.(string) strstr($change->path, 'owner'),
        $changeset->changes,
    );
    sort($changes);

    return $changes;
}

/**
 * @param  array<string, mixed>|bool  $old
 * @param  array<string, mixed>|bool  $new
 * @return array{list<string>, list<string>} the codes on a request and on a response, `!` marking breaking
 */
function unionBesideCodes(array|bool $old, array|bool $new): array
{
    $codes = static function (bool $request) use ($old, $new): array {
        $codes = array_map(
            static fn ($change): string => $change->code.($change->breaking ? '!' : '').' '.$change->path,
            (new SchemaComparator)->compare($old, $new, 'S', 'sch:v1:0000000000000000', $request),
        );
        sort($codes);

        return $codes;
    };

    return [$codes(true), $codes(false)];
}

$owner = ['$ref' => '#/components/schemas/Owner'];
$nothing = ['type' => 'null'];

// A null admitted: safe for a writer, breaking for a reader.
$admitted = ['GET breaking schema.nullable-widened owner.nullable', 'PUT schema.nullable-widened owner.nullable'];

// A null withdrawn refuses a value a writer is still sending, so it gates both sides.
$withdrawn = ['GET breaking schema.nullable-narrowed owner.nullable', 'PUT breaking schema.nullable-narrowed owner.nullable'];

it('reads a schema becoming a union of itself and null as the null admitted', function (array $schema, string $keyword) use ($nothing, $admitted, $withdrawn): void {
    $union = [$keyword => [$schema, $nothing]];

    // Every value the schema admitted is still admitted, so a request only widens; a response reader
    // may now meet a null. Never the union keyword arriving, nor the old schema's type or members going.
    expect(unionBesideChanges(unionBesideDiff($schema, $union)))->toBe($admitted)
        ->and(unionBesideChanges(unionBesideDiff($union, $schema)))->toBe($withdrawn);
})->with([
    'a reference' => [['$ref' => '#/components/schemas/Owner']],
    'an inline schema' => [['type' => 'string', 'maxLength' => 40]],
])->with(['anyOf', 'oneOf']);

it('reports the same finding as the other spellings of a nullable schema', function () use ($owner, $nothing, $admitted, $withdrawn): void {
    $string = ['type' => 'string'];
    $union = unionBesideChanges(unionBesideDiff($string, ['anyOf' => [$string, $nothing]]));

    // `nullable: true` at 3.0 and a `null` in the type union are the same statement, so one finding.
    expect($union)->toBe(unionBesideChanges(unionBesideDiff($string, $string + ['nullable' => true], '3.0.3')))
        ->and($union)->toBe(unionBesideChanges(unionBesideDiff($string, ['type' => ['string', 'null']])));

    // And for a reference, 3.0's spelling is the reference wrapped beside `nullable: true`.
    expect(unionBesideChanges(unionBesideDiff($owner, ['allOf' => [$owner], 'nullable' => true], '3.0.3')))->toBe($admitted)
        ->and(unionBesideChanges(unionBesideDiff(['allOf' => [$owner], 'nullable' => true], $owner, '3.0.3')))->toBe($withdrawn);
});

it('reads the null admitted the same way across OpenAPI versions', function (string $newer) use ($owner, $nothing, $admitted, $withdrawn): void {
    $union = ['anyOf' => [$owner, $nothing]];

    expect(unionBesideChanges(unionBesideDiff($owner, $union, '3.0.3', $newer)))->toBe($admitted)
        ->and(unionBesideChanges(unionBesideDiff($union, $owner, $newer, '3.0.3')))->toBe($withdrawn);
})->with(['3.1.1', '3.2.0']);

it('reads through a component that only wraps another to see it admits no null', function (string $keyword) use ($nothing, $admitted, $withdrawn): void {
    // `Guardian` is `Owner` under a description, so it admits only objects: null is disjoint from it, and
    // the union is the null admitted even under `oneOf`, not a branch whose overlap is unknown.
    $guardian = ['$ref' => '#/components/schemas/Guardian'];
    $union = [$keyword => [$guardian, $nothing]];

    expect(unionBesideChanges(unionBesideDiff($guardian, $union)))->toBe($admitted)
        ->and(unionBesideChanges(unionBesideDiff($union, $guardian)))->toBe($withdrawn);
})->with(['anyOf', 'oneOf']);

it('reads a description moved onto the union as no change of its own', function () use ($owner, $nothing, $admitted): void {
    $described = ['description' => 'Who owns the pet'];

    expect(unionBesideChanges(unionBesideDiff($owner + $described, ['anyOf' => [$owner, $nothing]] + $described)))->toBe($admitted);
});

it('reads any branch added beside the old schema as the widening a branch added to a union is', function (): void {
    $object = ['type' => 'object'];
    $string = ['type' => 'string'];

    // A writer's object is still valid, and a reader may now meet a string: the verdict a branch added
    // to an existing union gets, not the union keyword arriving.
    expect(unionBesideCodes($object, ['anyOf' => [$object, $string]]))->toBe([
        ['schema.any-of-branch-added S.anyOf.1'],
        ['schema.any-of-branch-added! S.anyOf.1'],
    ]);

    // A string is never an object, so exactly one branch matches every value either did.
    expect(unionBesideCodes($object, ['oneOf' => [$string, $object]]))->toBe([
        ['schema.one-of-branch-added S.oneOf.0'],
        ['schema.one-of-branch-added! S.oneOf.0'],
    ]);

    // A null beside other new branches is still the null admitted, beside the branches that are not.
    expect(unionBesideCodes($object, ['anyOf' => [$object, $string, ['type' => 'null']]]))->toBe([
        ['schema.any-of-branch-added S.anyOf.1', 'schema.nullable-widened S.nullable'],
        ['schema.any-of-branch-added! S.anyOf.1', 'schema.nullable-widened! S.nullable'],
    ]);
});

it('reads a union losing back down to the old schema as its branches removed', function (): void {
    $object = ['type' => 'object'];

    // A writer sending a string is refused, so the narrowing gates both sides.
    expect(unionBesideCodes(['anyOf' => [$object, ['type' => 'string']]], $object))->toBe([
        ['schema.any-of-branch-removed! S.anyOf.1'],
        ['schema.any-of-branch-removed! S.anyOf.1'],
    ]);
});

it('never reads a `oneOf` branch overlapping the old schema as a widening', function (): void {
    // 3 is a number and an integer, so it matches both branches and `oneOf` refuses it: a request that
    // sent 3 is now rejected, which must gate the request.
    [$onRequest] = unionBesideCodes(['type' => 'number'], ['oneOf' => [['type' => 'number'], ['type' => 'integer']]]);

    expect($onRequest)->toContain('schema.one-of-branch-added! S.oneOf');

    // The same for a null beside a schema that already admitted one: null now matches both branches.
    [$onRequest] = unionBesideCodes(['type' => ['string', 'null']], ['oneOf' => [['type' => ['string', 'null']], ['type' => 'null']]]);

    expect($onRequest)->toContain('schema.one-of-branch-added! S.oneOf');

    // With no document to read the reference through, nothing shows the null is disjoint from it.
    [$onRequest] = unionBesideCodes(['$ref' => '#/components/schemas/A'], ['oneOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]]);

    expect($onRequest)->toContain('schema.one-of-branch-added! S.oneOf');
});

it('never reads a `oneOf` beside a branch admitting anything as a widening', function (): void {
    // `true` admits every object, so every object now matches two branches and `oneOf` refuses it: a
    // request that sent one is rejected, which must gate the request.
    [$onRequest] = unionBesideCodes(['type' => 'object'], ['oneOf' => [['type' => 'object'], true]]);

    expect($onRequest)->toContain('schema.one-of-branch-added! S.oneOf');
});

it('reads a schema edited and made nullable at once, under one identity, as its edits and the null admitted', function (): void {
    // One identity is one schema, so the branch carrying it is the old schema edited, not a new branch:
    // its added property is reported as an edit, and the null beside it as the null admitted.
    $identity = ['x-docuccino' => ['id' => 'sch:v1:00000000000000aa']];
    $before = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]] + $identity;
    $after = ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']]] + $identity;

    expect(unionBesideCodes($before, ['anyOf' => [$after, ['type' => 'null']]]))->toBe([
        ['schema.nullable-widened S.nullable', 'schema.property-added S.properties.age'],
        ['schema.nullable-widened! S.nullable', 'schema.property-added S.properties.age'],
    ]);
});

it('never claims a null admitted where the old schema may already have admitted one', function (): void {
    // An untyped schema admits null already, and without the document a reference may name one that
    // does: the `anyOf` still only widens, so it is reported as the branch it is.
    expect(unionBesideCodes(['description' => 'Anything'], ['anyOf' => [[], ['type' => 'null']], 'description' => 'Anything']))->toBe([
        ['schema.any-of-branch-added S.anyOf.1'],
        ['schema.any-of-branch-added! S.anyOf.1'],
    ])->and(unionBesideCodes(['$ref' => '#/components/schemas/A'], ['anyOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]]))->toBe([
        ['schema.any-of-branch-added S.anyOf.1'],
        ['schema.any-of-branch-added! S.anyOf.1'],
    ]);
});

it('still reports a union that holds a different schema as written', function (): void {
    // No branch is the old schema, so the union is a constraint arriving, and a narrowing inside it
    // still gates a request.
    [$onRequest] = unionBesideCodes(['type' => 'string', 'maxLength' => 40], ['anyOf' => [['type' => 'string', 'maxLength' => 20], ['type' => 'null']]]);

    expect($onRequest)->toContain('schema.any-of-branch-added! S.anyOf');
});

it('reads a 3.0 `nullable: false` beside a one-branch composition as the branch, since absent is not nullable', function (): void {
    expect(unionBesideCodes(['allOf' => [['type' => 'string']], 'nullable' => false], ['type' => 'string']))->toBe([[], []]);
});

it('reads through a component naming itself without claiming what it admits', function (): void {
    // `Loop` resolves to nothing but itself, so nothing shows a null is disjoint from it and the `oneOf`
    // is compared as written, conservatively, rather than read through forever.
    $loop = ['$ref' => '#/components/schemas/Loop'];

    expect(unionBesideChanges(unionBesideDiff($loop, ['oneOf' => [$loop, ['type' => 'null']]])))
        ->toContain('PUT breaking schema.one-of-branch-added owner.oneOf');
});

it('reads a branch beside `false` as everything the union now admits', function (): void {
    // `false` admits nothing, so no value can match it and a second branch: the second is all that arrived.
    expect(unionBesideCodes(false, ['oneOf' => [false, ['type' => 'string']]]))->toBe([
        ['schema.one-of-branch-added S.oneOf.1'],
        ['schema.one-of-branch-added! S.oneOf.1'],
    ]);
});

it('encodes the old schema a fixed number of times, however wide the union beside it', function (): void {
    // Each branch is tested against the old schema, and re-encoding the old schema per branch makes a
    // large schema beside a wide union cost their product: an uploaded document would hold the diff for
    // minutes. The marker counts every encoding of the old schema, so the count is the work, not a timing.
    $encodings = static function (int $width): int {
        $marker = new class implements JsonSerializable
        {
            public int $count = 0;

            public function jsonSerialize(): string
            {
                $this->count++;

                return 'marker';
            }
        };

        // Every branch names the old schema's keywords, so none is settled before encoding.
        $union = ['anyOf' => array_map(static fn (int $i): array => ['type' => 'integer', 'enum' => [$i]], range(1, $width))];
        (new SchemaComparator)->compare(['type' => 'string', 'enum' => [$marker]], $union, 'S', 'sch:v1:0000000000000000', true);

        return $marker->count;
    };

    expect($encodings(200))->toBe($encodings(2))->toBeLessThan(4);
});
