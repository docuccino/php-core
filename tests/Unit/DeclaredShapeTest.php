<?php

declare(strict_types=1);

use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Patch\Contribution;

/*
 * A declaration states its shape whole. Keywords compose as a conjunction, so a keyword the
 * declaration replaced but did not restate publishes something nobody said — which is what these
 * pin, one mutually exclusive group at a time. `frozenShape()` lives in tests/Pest.php.
 */

it('retracts the keywords a declared shape supersedes', function (array $inferred, array $declared, array $expected): void {
    expect(frozenShape($inferred, $declared))->toBe($expected);
})->with([
    // The reported case: a MapT body says every key maps to V, and the declared shape names the keys.
    // Left standing, `additionalProperties` publishes a permission the author's shape never granted.
    'a closed object shape over an inferred map' => [
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
    ],
    // And the other way about: a declared map is not the closed shape inference recovered.
    'an open map over an inferred closed shape' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
    ],
    'a declared array over an inferred object' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ['type' => 'array', 'items' => ['type' => 'string']],
        ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    // A Schema Object's `$ref` siblings are applied, not ignored: `$ref` + `type: array` says the body
    // must satisfy both, which is a narrowing nobody declared — and usually nothing can satisfy.
    'a declared $ref over an inferred shape' => [
        ['type' => 'array', 'items' => ['type' => 'string']],
        ['$ref' => '#/components/schemas/Widget'],
        ['$ref' => '#/components/schemas/Widget'],
    ],
    'a declared scalar over an inferred container' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'uniqueItems' => true],
        ['type' => 'string'],
        ['type' => 'string'],
    ],
    'a declared union of types over an inferred object' => [
        ['type' => 'object', 'additionalProperties' => true],
        ['type' => ['string', 'null']],
        ['type' => ['string', 'null']],
    ],
    // Nothing else described the value, so there is nothing to retract and the declaration stands alone.
    'a declared shape over an empty draft' => [
        [],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
    ],
]);

it('publishes a declared closed shape closed', function (): void {
    // The consumer's whole question: may I send a key the author did not name? A surviving
    // `additionalProperties` answers yes, in the schema of a shape that named its keys.
    $frozen = frozenShape(
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']], 'required' => ['a', 'b']],
    );

    expect(array_keys($frozen))->toBe(['type', 'properties', 'required'])
        ->and($frozen)->not->toHaveKey('additionalProperties')
        ->and($frozen)->not->toHaveKey('patternProperties');
});

it('states a shape only where the write says what kind of value it is', function (array $declaration, array $expected): void {
    // A description or an example is not a shape, so it declares nothing about the body and supersedes
    // nothing — the independent-keyword case the guard was always right about.
    expect(SchemaKeywords::statesShape($declaration))->toBeFalse()
        ->and(frozenShape(['type' => 'object', 'additionalProperties' => ['type' => 'string']], $declaration))
        ->toBe($expected);
})->with([
    'a declared description' => [
        ['description' => 'The settings map.'],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'The settings map.'],
    ],
    'a declared example' => [
        ['example' => ['a' => 'b']],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'example' => ['a' => 'b']],
    ],
    'a declared deprecation' => [
        ['deprecated' => true],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'deprecated' => true],
    ],
]);

it('keeps what a higher layer stated beside a declared shape', function (): void {
    // Retraction is a guarded write, so it is bounded by precedence exactly as every other write is: an
    // overlay's keyword outranks the attribute declaring a type around it.
    $draft = new SchemaDraft;
    $draft->set('type', 'object', Contribution::inference());
    $draft->set('additionalProperties', ['type' => 'string'], Contribution::overlay());
    $draft->set('minProperties', 1, Contribution::overlay());

    $draft->declareShape(['type' => 'array', 'items' => ['type' => 'string']], Contribution::attribute());

    $frozen = $draft->freeze()->toArray();
    unset($frozen['x-docuccino']);

    // The declared type wins the field it outranks and the overlay's keywords stay put — the same answer
    // a keyword-by-keyword patch would give, which is the point: retraction adds no power of its own.
    expect($frozen)->toBe([
        'type' => 'array',
        'additionalProperties' => ['type' => 'string'],
        'minProperties' => 1,
        'items' => ['type' => 'string'],
    ]);
});

it('only shadows an equal layer, as a keyword write does', function (): void {
    // Two producers at one layer settle nothing: the incumbent keeps the field, so its keywords keep
    // their place too.
    expect(frozenShape(
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ['type' => 'string'],
        Contribution::attribute(),
        Contribution::attribute(),
    ))->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'string']]);
});

it('keeps a refinement the declared type still admits', function (array $inferred, array $declared, array $expected): void {
    // A refinement is true of values of its type, not of the shape that carried it: an inferred
    // `format: date-time` still describes a declared string, and an enum still lists what the server
    // accepts. Only a declared type that excludes the refinement retires it.
    expect(frozenShape($inferred, $declared))->toBe($expected);
})->with([
    'a format under the same type' => [
        ['type' => 'string', 'format' => 'date-time'],
        ['type' => 'string'],
        ['type' => 'string', 'format' => 'date-time'],
    ],
    'a length under the same type' => [
        ['type' => 'string', 'minLength' => 3],
        ['type' => 'string'],
        ['type' => 'string', 'minLength' => 3],
    ],
    'an enum under any type' => [
        ['type' => 'string', 'enum' => ['a', 'b']],
        ['type' => 'string'],
        ['type' => 'string', 'enum' => ['a', 'b']],
    ],
    'a string refinement under a declared object' => [
        ['type' => 'string', 'minLength' => 3, 'pattern' => '^a'],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
    ],
    'a numeric refinement under a declared string' => [
        ['type' => 'integer', 'minimum' => 1, 'multipleOf' => 2],
        ['type' => 'string'],
        ['type' => 'string'],
    ],
    'a numeric refinement under a declared number' => [
        ['type' => 'integer', 'minimum' => 1],
        ['type' => 'number'],
        ['type' => 'number', 'minimum' => 1],
    ],
]);

it('takes the nested property drafts with the shape they belonged to', function (): void {
    // `properties` has two halves — the keyword and the nested drafts, which freeze() publishes over it.
    // A shape that supersedes the keyword has to take the drafts too, or the declared body loses to the
    // properties it replaced.
    $draft = new SchemaDraft;
    $draft->set('type', 'object', Contribution::inference());
    $draft->property('inferred')->set('type', 'string', Contribution::inference());
    $draft->property('pinned')->set('type', 'integer', Contribution::overlay());

    $draft->declareShape(['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], Contribution::attribute());

    $frozen = $draft->freeze()->toArray();

    expect(array_keys($frozen['properties']))->toBe(['pinned'])
        ->and($frozen['properties']['pinned']['type'])->toBe('integer');
});

it('answers for every keyword it classifies', function (string $keyword, string $family): void {
    // The declaration states an object shape and restates nothing, so each family's answer is visible:
    // shape keywords go, an object refinement stays while a string one goes, annotations stay.
    $declaration = ['type' => 'object'];

    $superseded = match (true) {
        // The one keyword the declaration restates, and a restated keyword is overwritten on
        // precedence rather than retracted — which the second assertion pins for all of them.
        $keyword === 'type' => false,
        $family === 'shape' => true,
        $family === 'refinement' => ! in_array($keyword, ['minProperties', 'maxProperties', 'enum', 'const'], true),
        default => false,
    };

    expect(SchemaKeywords::isSuperseded($keyword, $declaration))->toBe($superseded)
        ->and(SchemaKeywords::isSuperseded($keyword, [...$declaration, $keyword => 'x']))->toBeFalse();
})->with(function () {
    foreach (SchemaKeywords::classification() as $keyword => $family) {
        yield $keyword => [$keyword, $family];
    }
});

it('leaves a keyword it cannot read exactly where it found it', function (string $keyword): void {
    // We do not retract what we cannot read: an unknown keyword survives a declared shape rather than
    // being dropped on a guess about what it meant.
    expect(SchemaKeywords::isSuperseded($keyword, ['type' => 'object']))->toBeFalse()
        ->and(frozenShape(['type' => 'array', $keyword => true], ['type' => 'object']))
        ->toBe(['type' => 'object', $keyword => true]);
})->with([
    'a vendor extension' => ['x-internal'],
    'a keyword from a later vocabulary' => ['unevaluatedProperties'],
]);

it('classifies every schema keyword the canonicalizer orders', function (): void {
    // The classification is the thing that goes stale — a hand list already did — so it is checked
    // against the canonicalizer's own schema keyword set rather than against a second copy of itself.
    $source = (string) file_get_contents(__DIR__.'/../../src/Canonical/Canonicalizer.php');
    $body = preg_split('/private function canonicalizeSchema/', $source)[1] ?? '';
    $body = preg_split('/\n    \}/', $body)[0] ?? '';

    preg_match_all("/'([^']+)' =>/", $body, $matches);
    $keywords = $matches[1];

    // A scan that stopped matching would turn this into a test of nothing.
    expect($keywords)->toHaveCount(52)
        ->toContain('$ref', 'type', 'properties', 'additionalProperties', 'items', 'description');

    expect(array_values(array_diff($keywords, array_keys(SchemaKeywords::classification()))))->toBe([]);
});
