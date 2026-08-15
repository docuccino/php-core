<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\NullTypeEngine;

/**
 * The dance every class-hoisting mapper delegates: name, cycle-break, publish. What these pin is the
 * bookkeeping either side of the builder — a name is only ever taken for a `$ref` that has already
 * gone out, and the expansion state is unwound whatever the builder does. One mapper is resolved once
 * and sees every route in the build, so anything it leaks it leaks for the rest of the run.
 */
it('publishes a class body under its own name and points a $ref at it', function (): void {
    $components = new ComponentRegistry;
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, $components, new RepresentationPolicy);

    $result = (new ComponentHoist)->hoist($context, 'App\\Data\\Widget', static fn (): array => ['type' => 'object']);

    expect($result->schema)->toBe(['$ref' => '#/components/schemas/Widget'])
        ->and($components->schemas())->toBe(['Widget' => ['type' => 'object']])
        ->and($components->schemaIds())->toBe(['Widget' => 'App\\Data\\Widget']);
});

it('breaks a self-reference with a $ref to the name the body will land on', function (): void {
    $components = new ComponentRegistry;
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, $components, new RepresentationPolicy);
    $hoist = new ComponentHoist;

    // The nested call is the same class again, exactly as a `parent: ?Node` property reaches it.
    $result = $hoist->hoist($context, 'App\\Data\\Node', static function () use ($hoist, $context): array {
        return ['type' => 'object', 'properties' => ['parent' => $hoist->hoist($context, 'App\\Data\\Node', static fn (): array => ['type' => 'object'])->schema]];
    });

    expect($result->schema)->toBe(['$ref' => '#/components/schemas/Node'])
        ->and($components->schemas()['Node']['properties']['parent'] ?? null)->toBe(['$ref' => '#/components/schemas/Node']);
});

it('takes no name at all for a class it cannot expand', function (): void {
    // Reserving up front and never releasing holds `Widget` against a component that never arrives, so
    // an unrelated class of the same name is pushed onto `Widget_2` — renamed by a route that
    // contributed nothing, with no collision to warn about.
    $components = new ComponentRegistry;
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, $components, new RepresentationPolicy);
    $hoist = new ComponentHoist;

    $degraded = $hoist->hoist($context, 'App\\Broken\\Widget', static fn (): ?array => null);
    $working = $hoist->hoist($context, 'App\\Data\\Widget', static fn (): array => ['type' => 'object', 'properties' => []]);

    expect($degraded->schema)->toBe(['type' => 'object'])
        ->and($degraded->confidence)->toBe(0.4)
        ->and($working->schema)->toBe(['$ref' => '#/components/schemas/Widget'])
        ->and(array_keys($components->schemas()))->toBe(['Widget'])
        ->and($components->diagnostics())->toBe([]);
});

it('publishes the degraded object under a name a self-reference already took', function (): void {
    // The other half: if a `$ref` to that name has gone out, dropping the name would leave the
    // document pointing at a component that does not exist. A vague shape beats a broken pointer.
    $components = new ComponentRegistry;
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, $components, new RepresentationPolicy);
    $hoist = new ComponentHoist;

    $inner = null;
    $result = $hoist->hoist($context, 'App\\Data\\Node', static function () use ($hoist, $context, &$inner): ?array {
        $inner = $hoist->hoist($context, 'App\\Data\\Node', static fn (): array => ['type' => 'object'])->schema;

        return null;
    });

    expect($inner)->toBe(['$ref' => '#/components/schemas/Node'])
        ->and($result->schema)->toBe(['$ref' => '#/components/schemas/Node'])
        ->and($components->schemas())->toHaveKey('Node')
        ->and($components->schemas()['Node'])->toBe(['type' => 'object']);
});

it('unwinds the class it was expanding when the builder throws', function (): void {
    // A mapper is resolved once per build, so a class left marked expanding stays marked for every
    // route after it — and the next route to reach it gets a `$ref` to a component nobody will hoist.
    $components = new ComponentRegistry;
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, $components, new RepresentationPolicy);
    $hoist = new ComponentHoist;

    expect(static fn () => $hoist->hoist($context, 'App\\Data\\Widget', static fn (): array => throw new RuntimeException('engine died')))
        ->toThrow(RuntimeException::class, 'engine died');

    // The route after it expands the same class from scratch, and gets a real component.
    $result = $hoist->hoist($context, 'App\\Data\\Widget', static fn (): array => ['type' => 'object']);

    expect($result->schema)->toBe(['$ref' => '#/components/schemas/Widget'])
        ->and($components->schemas())->toHaveKey('Widget');
});

it('reserves one name however many times a class refers back to itself', function (): void {
    $components = new ComponentRegistry;
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, $components, new RepresentationPolicy);
    $hoist = new ComponentHoist;

    $hoist->hoist($context, 'App\\Data\\Node', static function () use ($hoist, $context): array {
        return ['type' => 'object', 'properties' => [
            'parent' => $hoist->hoist($context, 'App\\Data\\Node', static fn (): array => [])->schema,
            'root' => $hoist->hoist($context, 'App\\Data\\Node', static fn (): array => [])->schema,
        ]];
    });

    expect(array_keys($components->schemas()))->toBe(['Node'])
        ->and($components->schemas()['Node']['properties'])->toBe([
            'parent' => ['$ref' => '#/components/schemas/Node'],
            'root' => ['$ref' => '#/components/schemas/Node'],
        ]);
});
