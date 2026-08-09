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
