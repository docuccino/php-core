<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\PublishedSide;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Support\JsonValue;

/*
 * A full UIR keeps the components the trail of an overridden value names, so the trail resolves; every
 * other artifact — an OpenAPI export, a `winners` or `none` UIR — drops them, since nothing a consumer
 * reads refers to them. The diff compares contracts, so it reads both sides without them: a fresh build
 * diffed against the artifact `docuccino:export` committed is the same API, and reads as no change.
 */

/**
 * One operation whose response a higher layer replaced twice over: the whole `responses` value, whose old
 * body named `Overridden` (and through it `Kind`), and the schema's `$ref`, whose old pointer named
 * `Inferred`. `Pet` and `Dog` are reached through a discriminator mapping and stay in every reading.
 *
 * @return array<string, mixed>
 */
$built = static fn (): array => [
    'openapi' => '3.2.0',
    'info' => ['title' => 'API', 'version' => '1.0.0'],
    'paths' => ['/things' => ['get' => [
        'responses' => ['200' => [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => [
                '$ref' => '#/components/schemas/Shown',
                'x-docuccino' => ['provenance' => [['producer' => 'attribute', 'layer' => 'attribute', 'fields' => ['$ref'], 'overrode' => [
                    ['field' => '$ref', 'value' => '#/components/schemas/Inferred', 'producer' => 'inference'],
                ]]]],
            ]]],
            'x-docuccino' => ['id' => 'res:v1:aaaaaaaaaaaaaaaa'],
        ]],
        'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa', 'provenance' => [[
            'producer' => 'attribute', 'layer' => 'attribute', 'fields' => ['responses'],
            'overrode' => [['field' => 'responses', 'value' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Overridden']]]]], 'producer' => 'inference']],
        ]]],
    ]]],
    'components' => ['schemas' => [
        'Shown' => ['type' => 'object', 'properties' => ['pet' => ['$ref' => '#/components/schemas/Pet']], 'x-docuccino' => ['id' => 'sch:v1:shownaaaaaaaaaaa']],
        'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['dog' => '#/components/schemas/Dog']], 'x-docuccino' => ['id' => 'sch:v1:petaaaaaaaaaaaaa']],
        'Cat' => ['type' => 'object', 'x-docuccino' => ['id' => 'sch:v1:cataaaaaaaaaaaaa']],
        'Dog' => ['type' => 'object', 'properties' => ['size' => ['type' => 'string', 'enum' => ['s', 'm']]], 'x-docuccino' => ['id' => 'sch:v1:dogaaaaaaaaaaaaa']],
        'Overridden' => ['type' => 'object', 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']], 'x-docuccino' => ['id' => 'sch:v1:overriddenaaaaaa']],
        'Kind' => ['type' => 'string', 'enum' => ['a', 'b'], 'x-docuccino' => ['id' => 'sch:v1:kindaaaaaaaaaaaa']],
        'Inferred' => ['type' => 'object', 'x-docuccino' => ['id' => 'sch:v1:inferredaaaaaaaa']],
    ]],
];

/** @param list<Change> $changes */
$codes = static fn (array $changes): array => array_map(static fn (Change $change): string => $change->code.' '.$change->path.($change->breaking ? ' BREAKING' : ''), $changes);

it('reads a fresh build and every artifact exported from it as the same API, in both directions', function (string $artifact) use ($built, $codes): void {
    $document = UirDocument::fromArray($built());
    $exported = UirDocument::fromArray(JsonValue::decode(match ($artifact) {
        'uir · winners' => (new UirEmitter)->emit($document, new EmitOptions(provenance: ProvenanceLevel::Winners)),
        'uir · none' => (new UirEmitter)->emit($document, new EmitOptions(provenance: ProvenanceLevel::None)),
        default => Formats::emit($artifact, $document, new EmitOptions(keepIds: true))->output,
    }));

    // Unread, the build is three schemas the artifact lacks: `Overridden`, `Kind` and `Inferred` added.
    expect($codes((new DocumentDiffer)->diff($exported, $document)->changes))->toBe([])
        ->and($codes((new DocumentDiffer)->diff($document, $exported)->changes))->toBe([]);
})->with(['uir · winners', 'uir · none', 'openapi-3.2', 'openapi-3.1', 'openapi-3.0']);

it('reports nothing for an edit to a component only the trail names, and still reports one reached by a mapping', function () use ($built, $codes): void {
    $narrowed = $built();
    $narrowed['components']['schemas']['Kind']['enum'] = ['a'];
    $narrowed['components']['schemas']['Inferred']['required'] = ['id'];
    $narrowed['components']['schemas']['Dog']['properties']['size']['enum'] = ['s'];

    // `Kind` and `Inferred` are in no request and no response, so no client can break on them. `Dog` is a
    // branch a client must accept, reached only through `Pet`'s discriminator mapping.
    expect($codes((new DocumentDiffer)->diff(UirDocument::fromArray($built()), UirDocument::fromArray($narrowed))->changes))
        ->toBe(['schema.enum-value-removed components.schemas.Dog.properties.size.enum BREAKING']);
});

it('reads a type the response stopped using as removed, never as still referenced by the trail that names it', function () use ($built, $codes): void {
    // Before: the inferred response is what is published, and `Inferred` is its type.
    $before = $built();
    $before['paths']['/things']['get']['responses']['200']['content']['application/json']['schema'] = ['$ref' => '#/components/schemas/Inferred'];
    unset($before['paths']['/things']['get']['x-docuccino']['provenance'], $before['components']['schemas']['Overridden'], $before['components']['schemas']['Kind']);

    $changes = $codes((new DocumentDiffer)->diff(UirDocument::fromArray($before), UirDocument::fromArray($built()))->changes);

    // Read with its trail, the new side names `Inferred` and does not declare it — which the differ calls
    // a pointer left dangling, and breaking. Nothing the new document publishes points at it.
    expect($changes)->toContain('schema.removed components.schemas.Inferred')
        ->and(array_filter($changes, static fn (string $code): bool => str_starts_with($code, 'schema.removed-still-referenced')))->toBe([]);
});

it('hands back a document with no provenance as it was read', function (): void {
    $document = UirDocument::fromArray(['openapi' => '3.2.0', 'info' => ['title' => 'API', 'version' => '1.0.0'], 'paths' => []]);

    expect(PublishedSide::of($document))->toBe($document);
});
