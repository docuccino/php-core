<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\SchemaComparator;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Identity\ContentHasher;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/**
 * A boolean at a subschema position, at every position 2020-12 has one, through every artifact the
 * emitters produce. `false` and `true` are opposites — nothing is valid against one and everything
 * against the other — and both had been shipping as `{}`, which is one of them; then both shipped as
 * written, which OpenAPI 3.0's draft-4-shaped Schema Object refuses at every position but
 * `additionalProperties`. Neither was caught, because no test put a boolean anywhere.
 *
 * The positions are read off {@see SchemaKeywords}, so one added to the table is covered here without
 * being named, and the meta-schema is the oracle: whatever spelling a target publishes, the artifact
 * answers to its own published schema.
 */

/**
 * A one-schema document with `$value` at the subschema slot `$keyword` defines: the keyword itself for
 * a single subschema, a member for a map, index 0 for a list.
 *
 * @return array<string, mixed>
 */
function booleanSubschemaDocument(string $keyword, mixed $value): array
{
    $slot = match (SchemaKeywords::positionOf($keyword)) {
        SchemaKeywords::POSITION_SCHEMA_MAP => [$keyword => ['Inner' => $value]],
        SchemaKeywords::POSITION_SCHEMA_LIST => [$keyword => [$value]],
        default => [$keyword => $value],
    };

    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => $slot]],
    ];
}

/** The JSON the artifact publishes for the `S` component, or `null` where it carries none. */
function booleanSubschemaPublished(string $format, array $document): string
{
    $graph = json_decode(
        Formats::emit($format, UirDocument::fromArray($document), new EmitOptions)->output,
        flags: JSON_THROW_ON_ERROR,
    );

    if ($format !== 'uir') {
        // The oracle. A spelling no validator accepts is the defect this file exists for.
        expect(OpenApiMetaSchema::findings($format, $graph))->toBe([], $format.' meta-schema');
    }

    return (string) json_encode($graph->components->schemas->S ?? null);
}

/** @return array<string, array{string}> */
function booleanSubschemaKeywords(): array
{
    $cases = [];

    foreach ([
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA),
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_MAP),
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST),
    ] as $keyword) {
        $cases[$keyword] = [$keyword];
    }

    return $cases;
}

it('covers every subschema position the table names', function (): void {
    // Anti-vacuity: a generator that stopped seeing a position would quietly stop proving anything
    // about it, which is how eight of these shipped inverted.
    expect(count(booleanSubschemaKeywords()))->toBeGreaterThan(19)
        ->and(array_keys(booleanSubschemaKeywords()))
        ->toContain('items', 'not', 'additionalProperties', 'properties', 'allOf', 'prefixItems', 'if');
});

it('publishes a boolean subschema as written in every dialect that spells one', function (string $keyword): void {
    $position = SchemaKeywords::positionOf($keyword);

    foreach ([false, true] as $value) {
        $written = $value ? 'true' : 'false';

        $expected = match ($position) {
            SchemaKeywords::POSITION_SCHEMA_MAP => '{"'.$keyword.'":{"Inner":'.$written.'}}',
            SchemaKeywords::POSITION_SCHEMA_LIST => '{"'.$keyword.'":['.$written.']}',
            default => '{"'.$keyword.'":'.$written.'}',
        };

        $document = booleanSubschemaDocument($keyword, $value);

        foreach (['uir', 'openapi-3.2', 'openapi-3.1'] as $format) {
            expect(booleanSubschemaPublished($format, $document))->toBe($expected, $keyword.' '.$written.' '.$format);
        }
    }
})->with(booleanSubschemaKeywords());

it('publishes a boolean subschema in the 3.0 spelling of the same constraint', function (string $keyword): void {
    // 3.0 defines a boolean at `additionalProperties` and nowhere else, and drops the keywords it has no
    // word for at all — so the rewrite is owed at exactly the positions that survive in between.
    $dropped = in_array($keyword, OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, true);
    $native = $keyword === 'additionalProperties';
    $position = SchemaKeywords::positionOf($keyword);

    foreach ([false => '{"not":{}}', true => '{}'] as $value => $rewritten) {
        $value = (bool) $value;
        $written = $value ? 'true' : 'false';
        $inner = $native ? $written : $rewritten;

        $expected = match (true) {
            $dropped => '{}',
            $position === SchemaKeywords::POSITION_SCHEMA_MAP => '{"'.$keyword.'":{"Inner":'.$inner.'}}',
            $position === SchemaKeywords::POSITION_SCHEMA_LIST => '{"'.$keyword.'":['.$inner.']}',
            default => '{"'.$keyword.'":'.$inner.'}',
        };

        expect(booleanSubschemaPublished('openapi-3.0', booleanSubschemaDocument($keyword, $value)))
            ->toBe($expected, $keyword.' '.$written);
    }
})->with(booleanSubschemaKeywords());

it('reports the 3.0 rewrite where it happens, and stays quiet where the boolean is native', function (string $keyword): void {
    $dropped = in_array($keyword, OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, true);
    $native = $keyword === 'additionalProperties';

    $report = (new OpenApi30DownlevelEmitter)
        ->emitWithReport(UirDocument::fromArray(booleanSubschemaDocument($keyword, false)))
        ->report;

    $codes = array_values(array_unique(array_map(static fn ($d): string => $d->code, $report->diagnostics)));

    $expected = match (true) {
        $dropped => ['downlevel.unsupported-keyword'],
        $native => [],
        default => ['downlevel.boolean-subschema'],
    };

    expect($codes)->toBe($expected, $keyword);
})->with(booleanSubschemaKeywords());

it('publishes an empty subschema slot as the empty schema, not as a list', function (string $keyword): void {
    // The other half of the same hazard: `[]` at a subschema slot is the empty object every time, and a
    // list nowhere. Only a LIST-valued keyword's own slot is genuinely a list, which is why the value is
    // placed at the slot the position defines rather than at the keyword.
    $position = SchemaKeywords::positionOf($keyword);

    $expected = match ($position) {
        SchemaKeywords::POSITION_SCHEMA_MAP => '{"'.$keyword.'":{"Inner":{}}}',
        SchemaKeywords::POSITION_SCHEMA_LIST => '{"'.$keyword.'":[{}]}',
        default => '{"'.$keyword.'":{}}',
    };

    $document = booleanSubschemaDocument($keyword, []);
    $dropped = in_array($keyword, OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, true);

    foreach (['uir', 'openapi-3.2', 'openapi-3.1'] as $format) {
        expect(booleanSubschemaPublished($format, $document))->toBe($expected, $keyword.' '.$format);
    }

    expect(booleanSubschemaPublished('openapi-3.0', $document))->toBe($dropped ? '{}' : $expected, $keyword.' 3.0');
})->with(booleanSubschemaKeywords());

/*
 * The slots a Schema Object hangs off something that is NOT one. The keywords inside a schema were the
 * first half of this; these four are one level further out and inverted the same `false` the same way,
 * so a media type declaring "no body is valid" published `{}` — "any body is". The 3.0 downlevel
 * converting the boolean correctly is what surfaced it: 3.0 said `{"not": {}}` where 3.2 said `{}`, and
 * one document cannot mean two things.
 */
it('publishes a boolean at every slot a Schema Object hangs off something that is not one', function (): void {
    $serialized = (new CanonicalJsonSerializer)->serialize((new Canonicalizer)->canonicalize([
        'components' => [
            'schemas' => ['Forbidden' => false, 'Anything' => true, 'Nonsense' => 7],
            'parameters' => ['q' => ['name' => 'q', 'in' => 'query', 'schema' => false]],
            'headers' => ['X-Trace' => ['schema' => false]],
            'requestBodies' => ['Body' => ['content' => ['application/json' => ['schema' => false]]]],
        ],
    ]));

    expect($serialized)
        ->toContain('"Forbidden": false')
        ->toContain('"Anything": true')
        // …and a value that is no schema at all still widens to the vague-but-valid one.
        ->toContain('"Nonsense": {}')
        // Once each for the parameter, the header and the media type.
        ->and(substr_count($serialized, '"schema": false'))->toBe(3);
});

/*
 * The diff half. `items: {type: string}` → `items: false` is the tightest narrowing an array's element
 * contract has — "any string" becomes "no element may exist" — and it reported NO change while the
 * document's `contentHash` moved, so `docuccino:diff` said the document had changed, named nothing and
 * raised no breaking verdict. At `properties` it did speak, and lied: the property was reported REMOVED
 * when it had been forbidden, and through the model's own hydration the same edit surfaced as
 * `schema.type-removed`, which is classed non-breaking — the strictest narrowing in the language
 * passing an `--enforce` release gate as safe.
 */

/**
 * One subschema slot on a schema, or the slot left out entirely.
 *
 * @return array<string, mixed>
 */
function booleanSubschemaAt(string $keyword, mixed $value): array
{
    if ($value === 'absent') {
        return ['type' => $keyword === 'items' ? 'array' : 'object'];
    }

    $slot = $keyword === 'properties' ? ['properties' => ['a' => $value]] : [$keyword => $value];

    return ['type' => $keyword === 'items' ? 'array' : 'object', ...$slot];
}

/** @return array<string, array{string}> */
function booleanSubschemaDiffPositions(): array
{
    return ['properties' => ['properties'], 'items' => ['items'], 'additionalProperties' => ['additionalProperties']];
}

it('reports a boolean subschema arriving and going, and classes it the same on both sides', function (string $keyword): void {
    $typed = ['type' => 'string'];
    $path = $keyword === 'properties' ? 'S.properties.a' : 'S.'.$keyword;

    $pairs = [
        // A schema that admits nothing arriving is the tightest narrowing there is, whichever
        // direction the schema serves.
        'typed → false' => [$typed, false, ['schema.always-invalid-added' => true]],
        'true → false' => [true, false, ['schema.always-invalid-added' => true]],
        'empty → false' => [[], false, ['schema.always-invalid-added' => true]],
        'absent → false' => ['absent', false, ['schema.always-invalid-added' => true]],
        // And going is the exact inverse: nothing was valid, now something is.
        'false → typed' => [false, $typed, ['schema.always-invalid-removed' => false]],
        'false → true' => [false, true, ['schema.always-invalid-removed' => false]],
        'false → absent' => [false, 'absent', ['schema.always-invalid-removed' => false]],
        // `true` IS the empty schema, so it is read as one rather than as a value of its own: losing a
        // type widens, and two spellings of "anything" are not a change at all.
        'typed → true' => [$typed, true, ['schema.type-removed' => false]],
        'true → empty' => [true, [], []],
        'false → false' => [false, false, []],
    ];

    foreach ($pairs as $label => [$old, $new, $expected]) {
        foreach ([true, false] as $request) {
            $changes = (new SchemaComparator)->compare(
                booleanSubschemaAt($keyword, $old),
                booleanSubschemaAt($keyword, $new),
                'S',
                'sch:v1:0000000000000000',
                $request,
            );

            $reported = [];
            foreach ($changes as $change) {
                // Every change reported for this pair is about the slot, never about the schema above it.
                expect($change->path)->toStartWith($path, $keyword.' '.$label);
                $reported[$change->code] = $change->breaking;
            }

            expect($reported)->toBe($expected, $keyword.' · '.$label.' · '.($request ? 'request' : 'response'));
        }
    }
})->with(booleanSubschemaDiffPositions());

it('reads an absent subschema as the empty one, so a constraint arriving there is reported', function (string $keyword): void {
    // The other half of reading these three positions at all: `items` and `additionalProperties` were
    // compared only when BOTH sides carried a readable one, so a constraint appearing at either was
    // silent. An absent `items` constrains no element and `additionalProperties` defaults to `true`, so
    // absent is the empty schema — and a type arriving over it narrows.
    $changes = (new SchemaComparator)->compare(
        booleanSubschemaAt($keyword, 'absent'),
        booleanSubschemaAt($keyword, ['type' => 'string']),
        'S',
        'sch:v1:0000000000000000',
        request: true,
    );

    expect(array_map(static fn ($c): string => $c->code.($c->breaking ? '!' : ''), $changes))
        ->toBe(['schema.type-added!']);
})->with(['items' => ['items'], 'additionalProperties' => ['additionalProperties']]);

it('names the narrowing a boolean makes on the path a diff actually runs', function (): void {
    $document = static fn (mixed $items): UirDocument => UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'operationId' => 'things.index',
            'responses' => ['200' => [
                'x-docuccino' => ['id' => 'res:v1:bbbbbbbbbbbbbbbb'],
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => ['type' => 'array', 'items' => $items]]],
            ]],
        ]]],
    ]);

    $changeset = (new DocumentDiffer)->diff($document(['type' => 'string']), $document(false));

    expect(array_map(static fn ($c): string => $c->code, $changeset->changes))->toBe(['schema.always-invalid-added'])
        ->and($changeset->changes[0]->breaking)->toBeTrue()
        ->and($changeset->changes[0]->path)->toBe('GET /things responses 200 application/json schema.items')
        // The symptom that started this: the bytes moved, so a diff that named nothing was reporting a
        // document it could not describe.
        ->and((new ContentHasher)->hash($document(['type' => 'string'])->toArray()))
        ->not->toBe((new ContentHasher)->hash($document(false)->toArray()));
});

it('sees a boolean at the media type schema slot rather than reporting the media type gone', function (): void {
    $document = static fn (mixed $schema): UirDocument => UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['post' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'operationId' => 'things.store',
            'requestBody' => ['content' => ['application/json' => ['schema' => $schema]]],
            'responses' => ['201' => [
                'x-docuccino' => ['id' => 'res:v1:bbbbbbbbbbbbbbbb'],
                'description' => 'made',
                'content' => ['application/json' => ['schema' => $schema]],
            ]],
        ]]],
    ]);

    $reported = static fn (mixed $old, mixed $new): array => array_map(
        static fn ($c): string => $c->code.($c->breaking ? '!' : ''),
        (new DocumentDiffer)->diff($document($old), $document($new))->changes,
    );

    // A schema the differ could not read used to be dropped, and a media type it never saw read as one
    // the response had stopped offering — a breaking verdict for a thing that had not happened.
    expect($reported(['type' => 'object'], false))
        ->toBe(['schema.always-invalid-added!', 'schema.always-invalid-added!'])
        ->and($reported(false, ['type' => 'object']))
        ->toBe(['schema.always-invalid-removed', 'schema.always-invalid-removed']);
});

it('widens a value that is no schema at all to a vague-but-valid one', function (string $keyword): void {
    // Neither an object nor a boolean is a schema anywhere, so it cannot be published as written: the
    // empty schema is vague and true, and `items: 7` is a document no validator accepts.
    $position = SchemaKeywords::positionOf($keyword);

    $expected = match ($position) {
        SchemaKeywords::POSITION_SCHEMA_MAP => '{"'.$keyword.'":{"Inner":{}}}',
        SchemaKeywords::POSITION_SCHEMA_LIST => '{"'.$keyword.'":[{}]}',
        default => '{"'.$keyword.'":{}}',
    };

    $dropped = in_array($keyword, OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, true);

    foreach ([null, 'nonsense', 7, 1.5] as $value) {
        $document = booleanSubschemaDocument($keyword, $value);

        foreach (['uir', 'openapi-3.2', 'openapi-3.1'] as $format) {
            expect(booleanSubschemaPublished($format, $document))->toBe($expected, $keyword.' '.$format);
        }

        expect(booleanSubschemaPublished('openapi-3.0', $document))->toBe($dropped ? '{}' : $expected, $keyword.' 3.0');
    }
})->with(booleanSubschemaKeywords());
