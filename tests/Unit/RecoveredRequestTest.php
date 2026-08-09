<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Tests\Fixtures\PinnedRequestClass;

/**
 * A POST route context sharing an explicit component registry so successive applies dedupe against
 * one document-wide registry (as the pipeline shares it across routes).
 *
 * @param  list<object>  $attributes
 */
function requestContext(ComponentRegistry $components, array $attributes = [], string $method = 'POST'): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor([$method], 'api/things'),
        actionRef: new ActionRef('', 'App\\Things', 'store'),
        attributes: new AttributeSet($attributes),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        components: $components,
    );
}

/**
 * @param  array<string, mixed>  $properties
 */
function objectSchema(array $properties): ValidationSchema
{
    return new ValidationSchema([
        'type' => 'object',
        'properties' => $properties,
        'required' => array_keys($properties),
    ]);
}

it('hoists a source-class body to one component $ref-ed by every operation using it', function (): void {
    $components = new ComponentRegistry;
    $schema = objectSchema(['name' => ['type' => 'string']]);

    $opA = new OperationDraft;
    $opB = new OperationDraft;
    (new RecoveredRequest)->apply($opA, requestContext($components), $schema, 'spatie-data', 'App\\CreateThing');
    (new RecoveredRequest)->apply($opB, requestContext($components), $schema, 'spatie-data', 'App\\CreateThing');

    $ref = ['$ref' => '#/components/schemas/CreateThing'];
    expect($opA->resolvedField('requestBody')['content']['application/json']['schema'])->toBe($ref)
        ->and($opB->resolvedField('requestBody')['content']['application/json']['schema'])->toBe($ref)
        // Same source class across two operations → exactly one component (deduped by identity).
        ->and(array_keys($components->schemas()))->toBe(['CreateThing'])
        ->and($components->schemas()['CreateThing'])->toBe($schema->schema);
});

it('keeps a body with no source class inline (nothing honest to name)', function (): void {
    $components = new ComponentRegistry;
    $schema = objectSchema(['name' => ['type' => 'string']]);

    $op = new OperationDraft;
    (new RecoveredRequest)->apply($op, requestContext($components), $schema, 'form-request', null);

    expect($op->resolvedField('requestBody')['content']['application/json']['schema'])->toBe($schema->schema)
        ->and($components->schemas())->toBe([]);
});

it('keeps the body inline when a #[BodyParameter] patches it at a higher layer (deviation rule)', function (): void {
    $components = new ComponentRegistry;
    $schema = objectSchema(['name' => ['type' => 'string']]);

    // A source class IS present, but a #[BodyParameter] will patch a body property at the attribute
    // layer — which cannot be expressed through a $ref — so this op keeps its body inline.
    $op = new OperationDraft;
    (new RecoveredRequest)->apply(
        $op,
        requestContext($components, [new BodyParameter(name: 'extra', type: 'string')]),
        $schema,
        'spatie-data',
        'App\\CreateThing',
    );

    expect($op->resolvedField('requestBody')['content']['application/json']['schema'])->toBe($schema->schema)
        ->and($components->schemas())->toBe([]);
});

it('emits two distinct components when a class is used as both request and response with different shapes', function (): void {
    $components = new ComponentRegistry;

    // Request phase runs first: the request body (rules-shape) hoists under the base name.
    $requestBody = objectSchema(['name' => ['type' => 'string']]);
    $op = new OperationDraft;
    (new RecoveredRequest)->apply($op, requestContext($components), $requestBody, 'spatie-data', 'App\\Thing');

    // Then the response side registers the same class's property-shape under its FQCN identity — a
    // different body, so it is deterministically suffixed rather than dedupe-colliding by name.
    $responseName = $components->registerSchema('Thing', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], 'App\\Thing');

    expect($op->resolvedField('requestBody')['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/Thing'])
        ->and($responseName)->toBe('Thing_2')
        ->and(array_keys($components->schemas()))->toBe(['Thing', 'Thing_2']);
});

it('suffixes a THIRD distinct claimant past _2 (collision ordering beyond N=2)', function (): void {
    $components = new ComponentRegistry;

    // The request phase claims the base name…
    $op = new OperationDraft;
    (new RecoveredRequest)->apply($op, requestContext($components), objectSchema(['name' => ['type' => 'string']]), 'spatie-data', 'App\\Thing');

    // …then two further DISTINCT shapes claim it, so the suffix must keep counting deterministically
    // rather than stopping at _2 (collision ordering was only ever proven to N=2).
    $second = $components->registerSchema('Thing', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], 'App\\Other\\Thing');
    $third = $components->registerSchema('Thing', ['type' => 'object', 'properties' => ['slug' => ['type' => 'string']]], 'App\\Third\\Thing');

    expect($second)->toBe('Thing_2')
        ->and($third)->toBe('Thing_3')
        ->and(array_keys($components->schemas()))->toBe(['Thing', 'Thing_2', 'Thing_3'])
        // Re-registering an EXISTING identity still dedupes onto its own suffixed name, not a fourth.
        ->and($components->registerSchema('Thing', ['type' => 'object', 'properties' => ['slug' => ['type' => 'string']]], 'App\\Third\\Thing'))->toBe('Thing_3')
        // One warning per genuine collision (two), none for the dedupe.
        ->and($components->diagnostics())->toHaveCount(2);
});

it('shares one component when a class is used on both sides with an identical shape', function (): void {
    $components = new ComponentRegistry;
    $shape = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];

    $op = new OperationDraft;
    (new RecoveredRequest)->apply($op, requestContext($components), new ValidationSchema($shape), 'spatie-data', 'App\\Thing');

    // Structurally identical response registration collapses onto the request component (one schema).
    $responseName = $components->registerSchema('Thing', $shape, 'App\\Thing');

    expect($responseName)->toBe('Thing')
        ->and(array_keys($components->schemas()))->toBe(['Thing']);
});

it('gives a #[SchemaId]-pinned source class a pinned, rename-stable request identity', function (): void {
    $components = new ComponentRegistry;
    $schema = objectSchema(['name' => ['type' => 'string']]);

    // PinnedRequestClass carries #[SchemaId('thing.v1')]. The request identity must honour the pin
    // (like the response side does) — `thing.v1#request`, NOT `<FQCN>#request` — so it stays stable
    // if the class is renamed, and the #request discriminator still keeps it distinct from the
    // response-side `thing.v1` identity.
    $op = new OperationDraft;
    (new RecoveredRequest)->apply($op, requestContext($components), $schema, 'spatie-data', PinnedRequestClass::class);

    $name = array_key_first($components->schemas());
    expect($components->schemaIds()[$name])->toBe('thing.v1#request');
});

it('does not hoist for read verbs (query parameters, never a body)', function (): void {
    $components = new ComponentRegistry;
    $schema = objectSchema(['q' => ['type' => 'string']]);

    $op = new OperationDraft;
    (new RecoveredRequest)->apply($op, requestContext($components, method: 'GET'), $schema, 'spatie-data', 'App\\SearchThing');

    expect($op->resolvedField('requestBody'))->toBeNull()
        ->and($components->schemas())->toBe([])
        ->and($op->hasParameter('query', 'q'))->toBeTrue();
});
