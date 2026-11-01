<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\JsonValue;

/*
 * A 3.0 document and a 3.1 or 3.2 one compared as what they say, not as how their versions spell it:
 * the exports of one unchanged API in two versions are no change at all, and a real change between
 * them is still one.
 */

function crossDialectDiff(string $old, string $new): Changeset
{
    $read = static fn (string $bytes): UirDocument => UirDocument::fromArray((array) JsonValue::decode($bytes));

    return (new DocumentDiffer)->diff($read($old), $read($new));
}

/**
 * @return array{string, string, string}
 */
function crossDialectExports(UirDocument $document, bool $keepIds = false): array
{
    $options = new EmitOptions(keepIds: $keepIds);

    return [
        (new OpenApi30DownlevelEmitter)->emit($document, $options),
        (new OpenApi31DownlevelEmitter)->emit($document, $options),
        (new OpenApi32Emitter)->emit($document, $options),
    ];
}

/**
 * One document using every spelling OpenAPI 3.0 writes differently from 2020-12.
 *
 * @return array<string, mixed>
 */
function crossDialectDocument(): array
{
    $schema = static fn (array $schema): array => ['content' => ['application/json' => ['schema' => $schema]]];

    return [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Attachments', 'version' => '1'],
        'paths' => [
            '/attachments' => [
                'get' => ['responses' => ['200' => ['description' => 'OK'] + $schema([
                    'type' => 'object',
                    'properties' => [
                        // `const`, a one-member `enum` in 3.0.
                        'kind' => ['type' => 'string', 'const' => 'file'],
                        // A type union with null, `nullable: true` in 3.0.
                        'name' => ['type' => ['string', 'null'], 'maxLength' => 40],
                        // More than one non-null type, an `anyOf` of single-type branches in 3.0.
                        'size' => ['type' => ['integer', 'string'], 'minimum' => 0, 'pattern' => '^[0-9]+$'],
                        // A nullable reference, `allOf` beside `nullable` in 3.0.
                        'owner' => ['anyOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'null']]],
                        // A nullable discriminated union, merged in beside `nullable` in 3.0.
                        'preview' => ['anyOf' => [
                            [
                                'oneOf' => [['$ref' => '#/components/schemas/Image'], ['$ref' => '#/components/schemas/Link']],
                                'discriminator' => ['propertyName' => 'kind'],
                            ],
                            ['type' => 'null'],
                        ]],
                        // A nullable closed set, null appended to the `enum` in 3.0.
                        'state' => ['anyOf' => [['type' => 'string', 'enum' => ['open', 'shut']], ['type' => 'null']]],
                        // Siblings of a `$ref`, hoisted into an `allOf` in 3.0.
                        'author' => ['$ref' => '#/components/schemas/Owner', 'description' => 'Who uploaded it'],
                        // A numeric exclusive bound, a boolean beside `minimum` in 3.0.
                        'ratio' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        // `examples`, the first kept as `example` in 3.0.
                        'tag' => ['type' => 'string', 'examples' => ['red', 'blue']],
                        // `contentEncoding: base64`, `format: byte` in 3.0.
                        'thumbnail' => ['type' => 'string', 'contentEncoding' => 'base64'],
                        // The null type, `{type: object, nullable: true, enum: [null]}` in 3.0.
                        'deleted' => ['type' => 'null'],
                        // A boolean subschema, which 3.0 has no spelling for.
                        'extra' => true,
                    ],
                ])]],
                // No responses at all, a placeholder `default` in 3.0.
                'delete' => ['summary' => 'Remove every attachment'],
            ],
        ],
        'webhooks' => [
            'attachmentCreated' => ['post' => ['responses' => ['200' => ['description' => 'Received']]]],
        ],
        'components' => ['schemas' => [
            'Owner' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'Image' => ['type' => 'object', 'properties' => ['kind' => ['const' => 'image']]],
            'Link' => ['type' => 'object', 'properties' => ['kind' => ['const' => 'link'], 'href' => ['type' => 'string', 'propertyNames' => ['maxLength' => 3]]]],
        ]],
    ];
}

it('finds no change between the 3.0 export of a document and its 3.1 or 3.2 one, either way round', function (): void {
    [$oas30, $oas31, $oas32] = crossDialectExports(UirDocument::fromArray(crossDialectDocument()));

    // Spelled differently in every member the document was built to exercise.
    expect(JsonValue::decode($oas30))->not->toEqual(JsonValue::decode($oas31));

    foreach ([[$oas30, $oas31], [$oas31, $oas30], [$oas30, $oas32], [$oas32, $oas30]] as [$old, $new]) {
        expect(crossDialectDiff($old, $new)->changes)->toBe([]);
    }
});

it('still reports a real change between a 3.0 document and a 3.1 one', function (): void {
    $edited = crossDialectDocument();
    unset($edited['paths']['/attachments']['get']['responses']['200']['content']['application/json']['schema']['properties']['name']);
    $edited['paths']['/attachments']['get']['responses']['200']['content']['application/json']['schema']['properties']['kind']['const'] = 'folder';

    [$oas30] = crossDialectExports(UirDocument::fromArray(crossDialectDocument()));
    [, $oas31] = crossDialectExports(UirDocument::fromArray($edited));

    $changeset = crossDialectDiff($oas30, $oas31);

    expect(array_map(static fn ($change): string => $change->code.' '.$change->path, $changeset->changes))->toBe([
        'schema.enum-value-removed GET /attachments responses 200 application/json schema.properties.kind.enum',
        'schema.property-removed GET /attachments responses 200 application/json schema.properties.name',
    ])->and($changeset->isBreaking())->toBeTrue();
});

it('compares two documents of one version as they are written', function (): void {
    // `const` and a one-member `enum` say the same thing, but nothing about the versions asks for them to
    // be read alike, so the change is reported the way it was written.
    $document = static function (array $kind): string {
        $array = crossDialectDocument();
        $array['openapi'] = '3.1.1';
        $array['paths']['/attachments']['get']['responses']['200']['content']['application/json']['schema']['properties']['kind'] = $kind;

        return (string) json_encode($array);
    };

    $codes = array_map(
        static fn ($change): string => $change->code,
        crossDialectDiff($document(['type' => 'string', 'enum' => ['file']]), $document(['type' => 'string', 'const' => 'file']))->changes,
    );

    expect($codes)->toContain('schema.enum-removed');
});

it('finds no change between the 3.0 and the 3.1 export of any golden', function (string $path): void {
    $document = UirDocument::fromArray((array) JsonValue::decode((string) file_get_contents($path)));

    foreach ([false, true] as $keepIds) {
        [$oas30, $oas31] = crossDialectExports($document, $keepIds);

        expect(crossDialectDiff($oas30, $oas31)->changes)->toBe([])
            ->and(crossDialectDiff($oas31, $oas30)->changes)->toBe([]);
    }
})->with(static function (): array {
    $goldens = [];
    foreach ([...(array) glob(dirname(__DIR__).'/Fixtures/golden/*.uir.json'), ...(array) glob(golden('*.uir.json'))] as $path) {
        $goldens[basename((string) $path)] = [(string) $path];
    }

    // A glob that matched nothing would pass forever.
    expect(count($goldens))->toBeGreaterThanOrEqual(60);

    return $goldens;
});

/**
 * A pet store whose `$properties` are written into a request and a response, with a parameter
 * referenced from `components`.
 *
 * @param  array<string, mixed>  $properties
 * @param  array<string, mixed>  $limit  the parameter's Reference Object
 * @return array<string, mixed>
 */
function crossDialectPets(string $openapi, array $properties, array $limit = ['$ref' => '#/components/parameters/Limit']): array
{
    $body = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $properties]]]];

    return [
        'openapi' => $openapi,
        'info' => ['title' => 'Pets', 'version' => '1'],
        'paths' => [
            '/pets' => ['get' => ['parameters' => [$limit], 'responses' => ['200' => ['description' => 'OK'] + $body]]],
            '/pets/{id}' => ['put' => ['requestBody' => $body, 'responses' => ['204' => ['description' => 'Saved']]]],
        ],
        'components' => [
            'schemas' => ['Owner' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
            'parameters' => ['Limit' => ['name' => 'limit', 'in' => 'query', 'description' => 'How many', 'schema' => ['type' => 'integer']]],
        ],
    ];
}

/** @return list<string> each change as its code and path */
function crossDialectChanges(Changeset $changeset): array
{
    return array_map(static fn ($change): string => ($change->breaking ? 'breaking ' : '').$change->code.' '.$change->path, $changeset->changes);
}

it('reads what OpenAPI 3.0 ignores as nothing, against a newer document and against another 3.0 one', function (): void {
    $encode = static fn (array $document): string => (string) json_encode($document);

    // Every member here says nothing at 3.0: the siblings of a `$ref` "SHALL be ignored", in a Schema
    // Object and in a Reference Object alike, and a boolean exclusive bound qualifies the `minimum` or
    // `maximum` beside it, so one that is `false` or has none qualifies nothing.
    $ignoring = crossDialectPets('3.0.3', [
        'owner' => ['$ref' => '#/components/schemas/Owner', 'nullable' => true, 'description' => 'Who owns it', 'maxProperties' => 1],
        'age' => ['type' => 'integer', 'maximum' => 30, 'exclusiveMaximum' => false],
        'weight' => ['type' => 'number', 'exclusiveMinimum' => true],
        'name' => ['type' => 'string', 'nullable' => false],
        'score' => ['type' => 'number', 'minimum' => 0, 'exclusiveMinimum' => true],
        'nick' => ['type' => 'string', 'nullable' => true],
    ], ['$ref' => '#/components/parameters/Limit', 'description' => 'At most this many']);

    $plain30 = crossDialectPets('3.0.3', [
        'owner' => ['$ref' => '#/components/schemas/Owner'],
        'age' => ['type' => 'integer', 'maximum' => 30],
        'weight' => ['type' => 'number'],
        'name' => ['type' => 'string'],
        'score' => ['type' => 'number', 'minimum' => 0, 'exclusiveMinimum' => true],
        'nick' => ['type' => 'string', 'nullable' => true],
    ]);

    $newer = static fn (string $openapi): array => crossDialectPets($openapi, [
        'owner' => ['$ref' => '#/components/schemas/Owner'],
        'age' => ['type' => 'integer', 'maximum' => 30],
        'weight' => ['type' => 'number'],
        'name' => ['type' => 'string'],
        'score' => ['type' => 'number', 'exclusiveMinimum' => 0],
        'nick' => ['type' => ['string', 'null']],
    ]);

    foreach ([$plain30, $newer('3.1.1'), $newer('3.2.0')] as $same) {
        expect(crossDialectDiff($encode($ignoring), $encode($same))->changes)->toBe([])
            ->and(crossDialectDiff($encode($same), $encode($ignoring))->changes)->toBe([]);
    }
});

it('reads a 3.0 `nullable` with no `type` beside it as its own newer export does', function (): void {
    // 3.0.4 reads this `nullable` as nothing and 3.0 code generators read it as admitting null; a 3.1
    // or 3.2 export has to choose, and states the wider. Compared with that export, the 3.0 source
    // is read the same way, or the one statement reads as a changed composition.
    $source = UirDocument::fromArray(crossDialectPets('3.0.3', [
        'state' => ['enum' => ['open', 'shut'], 'nullable' => true],
        'pet' => ['oneOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'string']], 'nullable' => true],
        'owner' => ['allOf' => [['$ref' => '#/components/schemas/Owner']], 'nullable' => true],
    ]));
    $written = (string) json_encode($source->toArray());

    foreach ([new OpenApi31DownlevelEmitter, new OpenApi32Emitter] as $emitter) {
        $export = $emitter->emit($source);

        expect(crossDialectDiff($written, $export)->changes)->toBe([])
            ->and(crossDialectDiff($export, $written)->changes)->toBe([]);
    }
});

it('reports a `nullable` arriving between two 3.0 documents where they spell it', function (): void {
    // Neither side has a newer reading to agree with, so each keeps its own spelling and the change
    // is named by the keyword its author wrote.
    $old = crossDialectPets('3.0.3', ['state' => ['enum' => ['open', 'shut']]]);
    $new = crossDialectPets('3.0.3', ['state' => ['enum' => ['open', 'shut'], 'nullable' => true]]);

    expect(crossDialectChanges(crossDialectDiff((string) json_encode($old), (string) json_encode($new))))->toBe([
        'breaking schema.nullable-widened GET /pets responses 200 application/json schema.properties.state.nullable',
        'schema.nullable-widened PUT /pets/{id} requestBody application/json schema.properties.state.nullable',
    ]);
});

it('still reports what moved between a 3.0 document and a newer one once both are read in one meaning', function (): void {
    $old = crossDialectPets('3.0.3', [
        'nick' => ['type' => 'string', 'nullable' => true],
        'age' => ['type' => 'integer', 'maximum' => 30, 'exclusiveMaximum' => false],
        'score' => ['type' => 'number', 'minimum' => 0, 'exclusiveMinimum' => true],
    ]);

    // `nick` stops admitting null, `age`'s bound moves down, and `score`'s bound stops being exclusive.
    $new = crossDialectPets('3.1.1', [
        'nick' => ['type' => 'string'],
        'age' => ['type' => 'integer', 'maximum' => 20],
        'score' => ['type' => 'number', 'minimum' => 0],
    ]);

    // A narrowing gates both sides; a widening gates only what a reader of the response meets.
    expect(crossDialectChanges(crossDialectDiff((string) json_encode($old), (string) json_encode($new))))->toBe([
        'breaking schema.refinement-narrowed GET /pets responses 200 application/json schema.properties.age.maximum',
        'breaking schema.nullable-narrowed GET /pets responses 200 application/json schema.properties.nick.nullable',
        'breaking schema.refinement-widened GET /pets responses 200 application/json schema.properties.score.exclusiveMinimum',
        'breaking schema.refinement-narrowed PUT /pets/{id} requestBody application/json schema.properties.age.maximum',
        'breaking schema.nullable-narrowed PUT /pets/{id} requestBody application/json schema.properties.nick.nullable',
        'schema.refinement-widened PUT /pets/{id} requestBody application/json schema.properties.score.exclusiveMinimum',
    ]);
});

it('finds no change between a hand-written 3.0 document and its export at every OpenAPI version', function (string $golden): void {
    // The source carries members 3.0 ignores beside a `$ref`, which every export leaves out.
    $source = (string) file_get_contents(dirname(__DIR__).'/Fixtures/openapi30-source.json');
    $export = (string) file_get_contents(dirname(__DIR__).'/Fixtures/golden/'.$golden);

    expect(crossDialectDiff($source, $export)->changes)->toBe([])
        ->and(crossDialectDiff($export, $source)->changes)->toBe([]);
})->with(['openapi30-source.openapi30.json', 'openapi30-source.openapi31.json', 'openapi30-source.openapi32.json']);

/**
 * Edits to a 3.0 document touching every member its 3.0 export either drops or restates and spells back.
 *
 * @return array<string, Closure(array<string, mixed>): array<string, mixed>>
 */
function crossDialectEdits(): array
{
    $walk = static function (mixed $node, Closure $edit) use (&$walk): mixed {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            $node[$key] = $walk($value, $edit);
        }

        return $edit($node);
    };

    $each = static fn (Closure $edit): Closure => static function (array $document) use ($walk, $edit): array {
        $count = 0;

        return $walk($document, static function (array $node) use ($edit, &$count): array {
            return $edit($node, $count++);
        });
    };

    return [
        'nullable turned off' => $each(static fn (array $n, int $i): array => ($n['nullable'] ?? null) === true && $i % 2 === 0 ? array_diff_key($n, ['nullable' => true]) : $n),
        'nullable turned on' => $each(static fn (array $n, int $i): array => is_string($n['type'] ?? null) && $i % 3 === 0 ? $n + ['nullable' => true] : $n),
        'nullable beside a lone allOf' => $each(static fn (array $n): array => array_keys($n) === ['allOf'] ? $n + ['nullable' => true] : $n),
        'a type changed' => $each(static fn (array $n, int $i): array => ($n['type'] ?? null) === 'string' && $i % 4 === 0 ? ['type' => 'integer'] + $n : $n),
        'bounds moved' => $each(static fn (array $n): array => is_int($n['maxLength'] ?? null) ? ['maxLength' => $n['maxLength'] + 1] + $n : (is_string($n['type'] ?? null) ? $n + ['maximum' => 9] : $n)),
        'bounds made exclusive' => $each(static fn (array $n): array => is_string($n['type'] ?? null) ? $n + ['minimum' => 0, 'exclusiveMinimum' => true, 'exclusiveMaximum' => true] : $n),
        'false spelled out' => $each(static fn (array $n, int $i): array => is_string($n['type'] ?? null) ? $n + ($i % 2 === 0 ? ['nullable' => false] : ['exclusiveMaximum' => false]) : $n),
        'a $ref described' => $each(static fn (array $n): array => is_string($n['$ref'] ?? null) ? $n + ['description' => 'Beside the reference.', 'maxProperties' => 1] : $n),
        'a property replaced' => $each(static fn (array $n): array => is_array($n['properties'] ?? null) && count($n['properties']) > 1
            ? ['properties' => array_slice($n['properties'], 1, null, true) + ['added' => ['type' => 'string']]] + $n
            : $n),
    ];
}

/*
 * Two 3.0 documents compare as their 3.0 exports say. That export drops what 3.0 ignores and spells the
 * rest back as written, so the differ drops only that and keeps the round trip through 3.1. Its findings
 * must be the ones the two full exports give, compared as written, for every edit over every 3.0
 * document the corpus holds.
 */
it('compares two 3.0 documents exactly as their full 3.0 exports compare', function (string $path): void {
    $source = Arr::stringKeyed(JsonValue::decode((string) file_get_contents($path)));
    // The export, labelled as a version the differ takes as written.
    $exported = static fn (array $document): UirDocument => UirDocument::fromArray(
        ['openapi' => '3.1.0'] + Arr::stringKeyed(JsonValue::decode((new OpenApi30DownlevelEmitter)->emit(UirDocument::fromArray($document), new EmitOptions(keepIds: true)))),
    );
    $found = 0;

    foreach (crossDialectEdits() as $edit) {
        $edited = $edit($source);

        foreach ([[$source, $edited], [$edited, $source]] as [$old, $new]) {
            $changes = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new))->toArray();

            expect($changes)->toBe((new DocumentDiffer)->diff($exported($old), $exported($new))->toArray());
            $found += count($changes['changes']);
        }
    }

    expect($found)->toBeGreaterThan(20);
})->with(static fn (): array => [
    dirname(__DIR__).'/Fixtures/openapi30-source.json',
    ...glob(dirname(__DIR__).'/Fixtures/golden/*.openapi30.json') ?: [],
]);
