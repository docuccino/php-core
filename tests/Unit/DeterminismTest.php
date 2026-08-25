<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Identity\ContentHasher;

beforeEach(function (): void {
    $this->emitter = new UirEmitter;
    $this->hasher = new ContentHasher;
});

it('emits byte-identical output across repeated runs', function (): void {
    $document = UirDocument::fromArray(workedExample());

    expect($this->emitter->emit($document))->toBe($this->emitter->emit($document));
});

it('produces the same bytes from the model as from the raw array', function (): void {
    $array = workedExample();
    $document = UirDocument::fromArray($array);

    expect($this->emitter->emit($document))->toBe($this->emitter->emitArray($array));
});

/**
 * Every UIR fixture in the tree, discovered rather than listed.
 *
 * @return array<string, array{string}>
 */
function fidelityFixtures(): array
{
    $fixtures = [];

    foreach (glob(dirname(__DIR__).'/Fixtures/*.json') ?: [] as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (is_array($decoded) && isset($decoded['uir'], $decoded['info'])) {
            $fixtures[basename($path, '.json')] = [basename($path)];
        }
    }

    ksort($fixtures);

    return $fixtures;
}

/*
 * The same equivalence over the whole corpus, because it is the shape of an entire class of defect: the
 * canonicaliser reads a raw array, the model is hydrated first, and anything the model coerces or DROPS
 * shows up here as two different documents from one input. It was asserted on a single fixture, which is
 * why a parameter's `schema: false` and a boolean `components.schemas` member could go missing in
 * hydration with the suite green. A model reader that stops seeing a member fails here on every fixture
 * carrying one, whatever the member turns out to be.
 */
it('hydrates without changing a byte the canonicaliser would have published', function (string $fixture): void {
    $array = loadFixture($fixture);

    expect($this->emitter->emit(UirDocument::fromArray($array)))->toBe($this->emitter->emitArray($array));
})->with(fidelityFixtures());

it('reads a plausible minimum of fixtures for that equivalence', function (): void {
    // A glob that stopped matching would report a clean bill of health over an empty corpus.
    expect(count(fidelityFixtures()))->toBeGreaterThanOrEqual(5)
        ->and(array_keys(fidelityFixtures()))->toContain('boolean-schema-slots.uir');
});

it('keeps contentHash stable when only x-docuccino.generator changes', function (): void {
    $a = workedExample();
    $b = workedExample();
    $b['x-docuccino']['generator'] = ['name' => 'docuccino/laravel', 'version' => '99.9.9', 'specVersion' => '1.0.0'];

    expect($this->hasher->hash($a))->toBe($this->hasher->hash($b));
});

it('keeps contentHash stable when only x-docuccino.diagnostics changes', function (): void {
    $a = workedExample();
    $b = workedExample();
    $b['x-docuccino']['diagnostics'] = [
        ['severity' => 'warning', 'code' => 'W1', 'message' => 'noise'],
    ];

    expect($this->hasher->hash($a))->toBe($this->hasher->hash($b));
});

it('changes contentHash when a documented field changes', function (): void {
    $a = workedExample();
    $b = workedExample();
    $b['paths']['/api/v1/forms']['get']['summary'] = 'A different summary';

    expect($this->hasher->hash($a))->not->toBe($this->hasher->hash($b));
});

it('excludes the contentHash field itself from the hash (recomputable, no chicken-and-egg)', function (): void {
    $a = workedExample();
    $b = workedExample();
    $b['x-docuccino']['document']['contentHash'] = 'a-completely-different-value';

    expect($this->hasher->hash($a))->toBe($this->hasher->hash($b));
});

it('recomputes the contentHash baked into the worked-example fixture', function (): void {
    $doc = workedExample();

    expect($this->hasher->hash($doc))->toBe($doc['x-docuccino']['document']['contentHash']);
});
