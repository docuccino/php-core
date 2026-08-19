<?php

declare(strict_types=1);

use Docuccino\Core\Patch\Layer;

/**
 * The precedence rungs and the strings they are written as in provenance. `fromLabel()` is the inverse
 * a reader of an emitted document needs, so the two have to stay each other's opposite for every case.
 */
it('reads back every layer it writes', function (Layer $layer, string $label, int $rank): void {
    expect($layer->label())->toBe($label)
        ->and($layer->value)->toBe($rank)
        ->and(Layer::fromLabel($label))->toBe($layer);
})->with([
    'fallback' => [Layer::Fallback, 'fallback', 5],
    'inference' => [Layer::Inference, 'inference', 10],
    'integration' => [Layer::Integration, 'integration', 20],
    'docblock' => [Layer::Docblock, 'docblock', 30],
    'attribute' => [Layer::Attribute, 'attribute', 40],
    'overlay' => [Layer::Overlay, 'overlay', 45],
    'config' => [Layer::Config, 'config', 50],
]);

it('names no rung for a label it does not know', function (string $label): void {
    expect(Layer::fromLabel($label))->toBeNull();
})->with([
    'a layer a later version might add' => ['recording'],
    'the case the enum uses internally' => ['Fallback'],
    'nothing at all' => [''],
]);
