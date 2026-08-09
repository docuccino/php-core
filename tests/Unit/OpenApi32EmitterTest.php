<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->emitter = new OpenApi32Emitter;
});

it('strips every x-docuccino member and the UIR-only top-level fields by default', function (): void {
    $json = $this->emitter->emit(UirDocument::fromArray(workedExample()));

    expect($json)->not->toContain('x-docuccino');
    expect($json)->not->toContain('"uir"');
    expect($json)->not->toContain('provenance');
    // Non-x-docuccino vendor members survive.
    expect($json)->toContain('x-enumDescriptions');
});

it('round-trips losslessly: OAS 3.2 output equals the x-docuccino-stripped canonical UIR', function (): void {
    $uir = workedExample();

    $oas = $this->emitter->emit(UirDocument::fromArray($uir));

    $stripped = stripDocuccino($uir);
    unset($stripped['$schema'], $stripped['uir']);

    $expected = (new CanonicalJsonSerializer)->serialize((new Canonicalizer)->canonicalize($stripped));

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

    $decoded = Yaml::parse($yaml);
    $json = json_decode($this->emitter->emit($document), true);

    expect($decoded)->toEqual($json);
});
