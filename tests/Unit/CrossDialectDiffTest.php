<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
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
