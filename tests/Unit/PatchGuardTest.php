<?php

declare(strict_types=1);

use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Patch\Remove;

/**
 * @return array<string, Contribution>
 */
function layerContributions(): array
{
    return [
        'fallback' => Contribution::fallback(),
        'inference' => Contribution::inference(),
        'integration' => Contribution::integration('spatie-query-builder'),
        'docblock' => Contribution::docblock(),
        'attribute' => Contribution::attribute(),
        'overlay' => Contribution::overlay(),
        'config' => Contribution::config(),
    ];
}

/**
 * @return list<array{string, Contribution, string, Contribution}>
 */
function orderedLayerPairs(): array
{
    $contributions = layerContributions();
    $labels = array_keys($contributions);

    $pairs = [];
    for ($i = 0; $i < count($labels); $i++) {
        for ($j = $i + 1; $j < count($labels); $j++) {
            $lower = $labels[$i];
            $higher = $labels[$j];
            $pairs[$lower.' < '.$higher] = [$lower, $contributions[$lower], $higher, $contributions[$higher]];
        }
    }

    return $pairs;
}

it('accepts a first write on an unset field', function (): void {
    $guard = new PatchGuard;

    expect($guard->apply('summary', 'List forms', Contribution::inference()))->toBe(PatchResult::Accepted);
    expect($guard->resolved())->toBe(['summary' => 'List forms']);
});

it('treats a null value as a no-op, never writing', function (): void {
    $guard = new PatchGuard;

    expect($guard->apply('summary', null, Contribution::attribute()))->toBe(PatchResult::NoOp);
    expect($guard->has('summary'))->toBeFalse();
    expect($guard->resolved())->toBe([]);
});

it('lets a higher layer override a lower one, recording the loser in overrode', function (string $lowerLabel, Contribution $lower, string $higherLabel, Contribution $higher): void {
    $guard = new PatchGuard;

    expect($guard->apply('summary', 'lower value', $lower))->toBe(PatchResult::Accepted);
    expect($guard->apply('summary', 'higher value', $higher))->toBe(PatchResult::Accepted);

    expect($guard->resolved())->toBe(['summary' => 'higher value']);

    $records = $guard->provenance()->records;
    expect($records)->toHaveCount(1);
    expect($records[0]->layer)->toBe($higherLabel);
    expect($records[0]->overrode)->toHaveCount(1);
    expect($records[0]->overrode[0]->field)->toBe('summary');
    expect($records[0]->overrode[0]->value)->toBe('lower value');
})->with(orderedLayerPairs());

it('shadows a lower-or-equal write over an existing higher owner', function (string $lowerLabel, Contribution $lower, string $higherLabel, Contribution $higher): void {
    $guard = new PatchGuard;

    expect($guard->apply('summary', 'higher value', $higher))->toBe(PatchResult::Accepted);
    expect($guard->apply('summary', 'lower value', $lower))->toBe(PatchResult::Shadowed);

    expect($guard->resolved())->toBe(['summary' => 'higher value']);

    $records = $guard->provenance()->records;
    expect($records)->toHaveCount(1);
    expect($records[0]->overrode)->toBe([]);
})->with(orderedLayerPairs());

it('shadows an equal-layer write of the same specificity', function (): void {
    $guard = new PatchGuard;

    expect($guard->apply('summary', 'first', Contribution::attribute()))->toBe(PatchResult::Accepted);
    expect($guard->apply('summary', 'second', Contribution::attribute()))->toBe(PatchResult::Shadowed);

    expect($guard->resolved())->toBe(['summary' => 'first']);
});

it('lets a more specific target beat a less specific one within a layer', function (): void {
    $guard = new PatchGuard;

    expect($guard->apply('summary', 'class attr', Contribution::attribute(specificity: 0)))->toBe(PatchResult::Accepted);
    expect($guard->apply('summary', 'method attr', Contribution::attribute(specificity: 1)))->toBe(PatchResult::Accepted);

    expect($guard->resolved())->toBe(['summary' => 'method attr']);
});

it('treats Remove as a real write that resolves to field-absent', function (): void {
    $guard = new PatchGuard;

    $guard->apply('deprecated', true, Contribution::inference());
    expect($guard->apply('deprecated', Remove::value(), Contribution::attribute()))->toBe(PatchResult::Accepted);

    expect($guard->has('deprecated'))->toBeTrue();
    expect($guard->resolved())->toBe([]);
});

it('groups fields sharing a producer into one deterministically ordered record', function (): void {
    $guard = new PatchGuard;

    $guard->apply('summary', 'S', Contribution::attribute());
    $guard->apply('description', 'D', Contribution::attribute());
    $guard->apply('operationId', 'op', Contribution::inference());

    $records = $guard->provenance()->records;

    expect($records)->toHaveCount(2);
    // Deterministic order: layer ascending (attribute before inference is false — sort is by layer string).
    $byLayer = [];
    foreach ($records as $record) {
        $byLayer[$record->layer] = $record->fields;
    }

    expect($byLayer['attribute'])->toBe(['description', 'summary']);
    expect($byLayer['inference'])->toBe(['operationId']);
});

it('accepts a fallback write to an unset field but lets any other layer override it', function (): void {
    $guard = new PatchGuard;

    // Fallback (rank 5) is the lowest layer: it writes to unset fields...
    expect($guard->apply('summary', 'fallback value', Contribution::fallback()))->toBe(PatchResult::Accepted);
    expect($guard->resolved())->toBe(['summary' => 'fallback value']);

    // ...but loses to inference, the next layer up.
    expect($guard->apply('summary', 'inferred value', Contribution::inference()))->toBe(PatchResult::Accepted);
    expect($guard->resolved())->toBe(['summary' => 'inferred value']);
});

it('rejects a fallback write over any existing owner', function (): void {
    $guard = new PatchGuard;

    $guard->apply('summary', 'inferred value', Contribution::inference());

    expect($guard->apply('summary', 'fallback value', Contribution::fallback()))->toBe(PatchResult::Shadowed);
    expect($guard->resolved())->toBe(['summary' => 'inferred value']);
});

it('backs the precedence ranks stated in the design doc', function (): void {
    expect(Layer::Fallback->value)->toBe(5);
    expect(Layer::Inference->value)->toBe(10);
    expect(Layer::Integration->value)->toBe(20);
    expect(Layer::Docblock->value)->toBe(30);
    expect(Layer::Attribute->value)->toBe(40);
    expect(Layer::Overlay->value)->toBe(45);
    expect(Layer::Config->value)->toBe(50);
});
