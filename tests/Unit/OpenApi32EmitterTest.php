<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Core\Tests\Support\EmittedDocument;

beforeEach(function (): void {
    $this->emitter = new OpenApi32Emitter;
});

it('strips every x-docuccino member by default', function (): void {
    $json = $this->emitter->emit(UirDocument::fromArray(workedExample()));

    expect($json)->not->toContain('x-docuccino');
    expect($json)->not->toContain('provenance');
    // Non-x-docuccino vendor members survive.
    expect($json)->toContain('x-enumDescriptions');
});

/**
 * A document written before UIR 2.0, which is the only population the emitter's `$schema`/`uir` strip
 * still serves. Nothing this version builds carries either member, so without a subject shaped like
 * this the strip is a line no test executes — and deleting it passes the whole suite.
 *
 * There used to be one, and this change is what removed it: the adapter's viewer test drove a legacy
 * artifact through this very code, and moving the two members took the root `uir` out of that document
 * along with every other. The adapter has it back and widened, as the trust boundary it actually is
 * (`ViewerTest`, "never streams node provenance"). This is the core half of the same story rather than
 * a second copy of it: the strip is core's, and a standalone `docuccino/core` checkout has to be able
 * to prove its own emitter without the adapter's suite to lean on.
 *
 * Hand-written rather than committed as a fixture because the corpus is what THIS version emits: a
 * legacy artifact in it would be held to the 2.0 schema by every guard that reads the tree, and would
 * have to be excepted from each of them to prove one line.
 *
 * @return array<string, mixed>
 */
function preTwoPointZeroArtifact(): array
{
    return [
        '$schema' => 'https://spec.docuccino.app/uir/1.1/schema.json',
        'uir' => '1.1.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Written by an older tool', 'version' => '1.0.0'],
        'paths' => ['/widgets' => ['get' => [
            'x-docuccino' => ['id' => 'op:v1:mfz3q8k2w9r7t1ua', 'provenance' => [
                ['producer' => 'route', 'layer' => 'inference', 'fields' => ['operationId']],
            ]],
            'operationId' => 'widgets.index',
            'responses' => ['200' => ['description' => 'ok']],
        ]]],
        'x-docuccino' => ['document' => ['id' => 'doc:legacy']],
    ];
}

it('drops the root members an artifact written before UIR 2.0 carries', function (): void {
    // The hydration half first: they survive the round trip in `rest`, so the emitter is what has to
    // remove them rather than the model quietly losing them.
    $hydrated = UirDocument::fromArray(preTwoPointZeroArtifact())->toArray();

    expect($hydrated['$schema'] ?? null)->toBe('https://spec.docuccino.app/uir/1.1/schema.json')
        ->and($hydrated['uir'] ?? null)->toBe('1.1.0');

    $emitted = json_decode(
        $this->emitter->emit(UirDocument::fromArray(preTwoPointZeroArtifact())),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // Neither member reaches the output, and what does is a conformant OpenAPI 3.2 document — the
    // OpenAPI Object admits neither, so re-emitting one would serve an invalid document to whoever
    // is reading the page.
    expect($emitted)->not->toHaveKey('$schema')
        ->and($emitted)->not->toHaveKey('uir')
        ->and($emitted)->not->toHaveKey('x-docuccino')
        ->and($emitted['paths']['/widgets']['get'])->not->toHaveKey('x-docuccino')
        ->and($emitted['info']['title'] ?? null)->toBe('Written by an older tool');

    expect(OpenApiMetaSchema::findings(
        'openapi-3.2',
        json_decode($this->emitter->emit(UirDocument::fromArray(preTwoPointZeroArtifact())), flags: JSON_THROW_ON_ERROR),
    ))->toBe([]);
});

it('round-trips losslessly: OAS 3.2 output equals the x-docuccino-stripped canonical UIR', function (): void {
    $uir = workedExample();

    $oas = $this->emitter->emit(UirDocument::fromArray($uir));

    $expected = (new CanonicalJsonSerializer)->serialize((new Canonicalizer)->canonicalize(stripDocuccino($uir)));

    expect($oas)->toBe($expected);
});

it('re-emits ids as flat x-docuccino-id when keepIds is enabled', function (): void {
    $options = (new EmitOptions)->withKeepIds();

    $json = $this->emitter->emit(UirDocument::fromArray(workedExample()), $options);

    expect($json)->toContain('x-docuccino-id');
    expect($json)->toContain('op:v1:mfz3q8k2w9r7t1ua');
    expect($json)->not->toContain('provenance');
});

it('maps mock hints to a configurable faker member', function (): void {
    $options = (new EmitOptions)->withMockFakerKey('x-faker');

    $json = $this->emitter->emit(UirDocument::fromArray(workedExample()), $options);

    expect($json)->toContain('"x-faker": "numberBetween:1,100"');
});

it('drops mock hints when no faker key is configured', function (): void {
    $json = $this->emitter->emit(UirDocument::fromArray(workedExample()));

    expect($json)->not->toContain('numberBetween');
});

it('emits deterministic bytes across repeated runs', function (): void {
    $document = UirDocument::fromArray(workedExample());

    expect($this->emitter->emit($document))->toBe($this->emitter->emit($document));
});

it('emits YAML that carries the same structure as the JSON', function (): void {
    $document = UirDocument::fromArray(workedExample());

    $yaml = $this->emitter->emit($document, (new EmitOptions)->withYaml());

    expect($yaml)->toContain('openapi: 3.2.0');
    expect($yaml)->not->toContain('x-docuccino');

    // Both sides read WITHOUT collapsing map and sequence, which is the one claim this test's name makes
    // and the one it could not check: `Yaml::parse()` answers a PHP array for a mapping and for a
    // sequence, and so does an associative `json_decode`, so `paths: {}` against `paths: []` was equal on
    // both sides at once ({@see EmittedDocument}).
    expect(EmittedDocument::differences(
        json_decode($this->emitter->emit($document), flags: JSON_THROW_ON_ERROR),
        EmittedDocument::parseYaml($yaml),
    ))->toBe([]);
});
