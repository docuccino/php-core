<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;

/**
 * Byte-exact golden tests (QA C2): the emitters must reproduce committed artifacts to the byte, so
 * a change in canonical form, member order or float rendering fails loudly. Regenerate the goldens
 * deliberately when the canonical form intentionally changes — never edit them to match new output.
 */

/**
 * @return array<string, array{string, string}>
 */
function goldenFixtures(): array
{
    return [
        'worked-example' => ['worked-example.json', 'worked-example'],
        'kitchen-sink' => ['kitchen-sink.uir.json', 'kitchen-sink'],
    ];
}

it('emits UIR JSON byte-identical to the committed golden', function (string $fixture, string $base): void {
    $document = UirDocument::fromArray(loadFixture($fixture));

    expect((new UirEmitter)->emit($document))->toBe(loadGolden($base.'.uir.json'));
})->with(goldenFixtures());

it('emits OpenAPI 3.2 JSON byte-identical to the committed golden', function (string $fixture, string $base): void {
    $document = UirDocument::fromArray(loadFixture($fixture));

    expect((new OpenApi32Emitter)->emit($document))->toBe(loadGolden($base.'.openapi32.json'));
})->with(goldenFixtures());

it('emits OpenAPI 3.1 JSON byte-identical to the committed golden', function (string $fixture, string $base): void {
    $document = UirDocument::fromArray(loadFixture($fixture));

    expect((new OpenApi31DownlevelEmitter)->emit($document))->toBe(loadGolden($base.'.openapi31.json'));
})->with(goldenFixtures());

it('emits OpenAPI 3.2 YAML byte-identical to the committed golden', function (string $fixture, string $base): void {
    $document = UirDocument::fromArray(loadFixture($fixture));

    expect((new OpenApi32Emitter)->emit($document, (new EmitOptions)->withYaml()))->toBe(loadGolden($base.'.openapi32.yaml'));
})->with(goldenFixtures());

it('is stable through a decode/re-encode round trip', function (string $fixture): void {
    // emit(fromArray(decode(emit($doc)))) === emit($doc): the emitted bytes decode back to a model
    // that re-emits the exact same bytes.
    $emitter = new UirEmitter;
    $document = UirDocument::fromArray(loadFixture($fixture));

    $once = $emitter->emit($document);
    $reDecoded = json_decode($once, true, flags: JSON_THROW_ON_ERROR);
    $twice = $emitter->emit(UirDocument::fromArray($reDecoded));

    expect($twice)->toBe($once);
})->with([
    'worked-example' => ['worked-example.json'],
    'kitchen-sink' => ['kitchen-sink.uir.json'],
]);
