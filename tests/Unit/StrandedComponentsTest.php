<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\SpecValidation\Validator;

/*
 * A component the document reaches only through what an emission drops goes with it. The trail a winner
 * keeps of the value it overrode names that value's components, so a component only the trail refers to
 * is kept for the trail in a full UIR, and is a dead type in every document the trail is not carried in.
 * A component nothing reached in the first place was put there on purpose, and every emission keeps it.
 */

/**
 * One operation whose body and response a higher layer replaced: `Shown` is what it publishes,
 * `Overridden` and `OverriddenBody` what it published before, named only by the provenance trail. `Kept` and `ApiKey` are reached by
 * nothing but are the author's.
 *
 * @return array<string, mixed>
 */
$document = static fn (): array => [
    'openapi' => '3.2.0',
    'info' => ['title' => 'API', 'version' => '1.0.0'],
    'paths' => ['/things' => ['get' => [
        'operationId' => 'things.list',
        'responses' => ['200' => [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Shown']]],
        ]],
        'x-docuccino' => ['provenance' => [[
            'producer' => 'attribute',
            'layer' => 'attribute',
            'fields' => ['requestBody', 'responses'],
            'overrode' => [
                ['field' => 'requestBody', 'value' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/OverriddenBody']]]], 'producer' => 'inference'],
                ['field' => 'responses', 'value' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Overridden']]]]], 'producer' => 'inference'],
            ],
        ]]],
    ]]],
    'components' => [
        'schemas' => [
            'Shown' => ['type' => 'object', 'properties' => ['tag' => ['$ref' => '#/components/schemas/Tag']]],
            'Tag' => ['type' => 'string'],
            'Overridden' => ['type' => 'object', 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']]],
            'OverriddenBody' => ['type' => 'object'],
            'Kind' => ['type' => 'string', 'enum' => ['a', 'b']],
            'Kept' => ['type' => 'object', 'example' => ['$ref' => '#/components/schemas/OnlyAnExampleNamesMe']],
            'OnlyAnExampleNamesMe' => ['type' => 'string'],
        ],
        'securitySchemes' => ['ApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key']],
    ],
];

it('drops what only the overridden value referred to from every OpenAPI document, and keeps the rest', function (string $format) use ($document): void {
    $emitted = json_decode(Formats::emit($format, UirDocument::fromArray($document()), new EmitOptions)->output, true, flags: JSON_THROW_ON_ERROR);

    // `Kind` goes too: it is reached only through `Overridden`. `OnlyAnExampleNamesMe` stays — an example
    // states a value, it refers to nothing, so the source never reached it either.
    expect(array_keys($emitted['components']['schemas']))->toEqualCanonicalizing(['Shown', 'Tag', 'Kept', 'OnlyAnExampleNamesMe'])
        ->and($emitted['components']['securitySchemes'])->toHaveKey('ApiKey');
})->with(Formats::plainOpenApi());

it('keeps them in a full UIR, whose trail still names them, and drops them where the trail goes', function () use ($document): void {
    $uir = UirDocument::fromArray($document());
    $schemas = static fn (ProvenanceLevel $level): array => array_keys(json_decode((new UirEmitter)->emit($uir, new EmitOptions(provenance: $level)), true, flags: JSON_THROW_ON_ERROR)['components']['schemas']);

    expect($schemas(ProvenanceLevel::Full))->toEqualCanonicalizing(['Shown', 'Tag', 'Overridden', 'OverriddenBody', 'Kind', 'Kept', 'OnlyAnExampleNamesMe'])
        ->and($schemas(ProvenanceLevel::Winners))->toEqualCanonicalizing(['Shown', 'Tag', 'Kept', 'OnlyAnExampleNamesMe'])
        ->and($schemas(ProvenanceLevel::None))->toEqualCanonicalizing(['Shown', 'Tag', 'Kept', 'OnlyAnExampleNamesMe']);
});

it('drops a bucket the drop empties, and keeps components the document has none of', function () use ($document): void {
    $only = $document();
    $only['paths']['/things']['get']['responses']['200']['content']['application/json']['schema'] = ['type' => 'object'];
    $only['components'] = ['schemas' => array_intersect_key($only['components']['schemas'], array_flip(['Overridden', 'OverriddenBody', 'Kind']))];

    // Only the trail reached them, so the bucket goes, and `components` with it.
    expect(json_decode((new OpenApi32Emitter)->emit(UirDocument::fromArray($only)), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('components');

    $bare = $document();
    unset($bare['components']);

    expect(json_decode((new OpenApi31DownlevelEmitter)->emit(UirDocument::fromArray($bare)), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('components');
});

it('drops from a 3.0 document what only its webhooks referred to, which 3.0 cannot carry', function (): void {
    $emitted = json_decode((new OpenApi30DownlevelEmitter)->emit(UirDocument::fromArray([
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => ['responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']]]]]]]],
        'webhooks' => ['thing.made' => ['post' => [
            'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ThingMade']]]],
            'responses' => ['200' => ['description' => 'OK']],
        ]]],
        'components' => ['schemas' => [
            'Thing' => ['type' => 'object'],
            // Reached by the webhook and by nothing else, but for a pointer INTO it from `Thing`'s sibling.
            'ThingMade' => ['type' => 'object', 'properties' => ['thing' => ['$ref' => '#/components/schemas/Thing']]],
            'Shared' => ['type' => 'object', 'properties' => ['at' => ['$ref' => '#/components/schemas/Stamp/properties/at']]],
            'Stamp' => ['type' => 'object', 'properties' => ['at' => ['type' => 'string']]],
        ]],
    ])), true, flags: JSON_THROW_ON_ERROR);

    // `Shared` and `Stamp` were never reached, so they are the author's and stay.
    expect(array_keys($emitted['components']['schemas']))->toEqualCanonicalizing(['Thing', 'Shared', 'Stamp']);
});

it('counts a pointer into a component as reaching the component', function (): void {
    $emitted = json_decode((new OpenApi32Emitter)->emit(UirDocument::fromArray([
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/at' => ['get' => [
            'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Stamp/properties/at']]]]],
            'x-docuccino' => ['provenance' => [['producer' => 'attribute', 'layer' => 'attribute', 'fields' => ['responses'], 'overrode' => [['field' => 'responses', 'value' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Gone']]]]], 'producer' => 'inference']]]]],
        ]]],
        'components' => ['schemas' => [
            'Stamp' => ['type' => 'object', 'properties' => ['at' => ['type' => 'string']]],
            'Gone' => ['type' => 'object'],
        ]],
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($emitted['components']['schemas']))->toBe(['Stamp']);
});

it('reads a trail written the way the UIR schema seals it', function () use ($document): void {
    expect((new Validator)->validate($document())->errors)->toBe([]);
});

/*
 * The rule over every UIR document the tree holds, in every emission that drops something: what an
 * emitted document publishes and nothing in it reaches, its source did not reach either. Stated by
 * {@see unreachableComponents()}, a walk of its own, rather than by asking the emitter which components it
 * reached. A document the build recorded reaches all of its components; a hand-written one may keep some
 * on purpose, and those must survive every emission.
 */
it('publishes no component an emission stranded, over every recorded document', function (string $path): void {
    $source = loadDocument($path);
    $document = UirDocument::fromArray($source);
    $kept = unreachableComponents($source);

    $emissions = [];
    foreach (Formats::plainOpenApi() as $format) {
        $emissions[$format] = Formats::emit($format, $document, new EmitOptions)->output;
    }
    foreach ([ProvenanceLevel::Winners, ProvenanceLevel::None] as $level) {
        $emissions['uir · '.$level->value] = (new UirEmitter)->emit($document, new EmitOptions(provenance: $level));
    }

    foreach ($emissions as $emission => $output) {
        expect(array_values(array_diff(unreachableComponents(json_decode($output, true, flags: JSON_THROW_ON_ERROR)), $kept)))
            ->toBe([], $emission);
    }
})->with(static fn (): array => array_combine(
    array_map(static fn (string $path): string => basename($path), uirDocuments()),
    array_map(static fn (string $path): array => [$path], uirDocuments()),
));

/** A scan that finds nothing must fail: the domain is the whole tree, and most of it reaches components. */
it('reads a plausible minimum of documents and components for that rule', function (): void {
    $documents = uirDocuments();
    $components = 0;
    foreach ($documents as $path) {
        $components += count(loadDocument($path)['components']['schemas'] ?? []);
    }

    expect(count($documents))->toBeGreaterThanOrEqual(60)
        ->and($components)->toBeGreaterThanOrEqual(300)
        ->and(unreachableComponents(['paths' => [], 'components' => ['schemas' => ['A' => []]]]))->toBe(['schemas/A']);
});
