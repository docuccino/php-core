<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Extensions\Validation\RequestSchemaBuilder;
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
        // Same source class across two operations gives exactly one component, deduped by identity.
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

    // A source class is present, but a #[BodyParameter] patches a body property at the attribute layer,
    // which a $ref can't express — so this op keeps its body inline.
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

    // Then the response side registers the same class's property shape under its FQCN identity. It's a
    // different body, so it gets a deterministic suffix rather than colliding by name.
    $responseName = $components->registerSchema('Thing', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], 'App\\Thing');

    expect($op->resolvedField('requestBody')['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/Thing'])
        ->and($responseName)->toBe('Thing_2')
        ->and(array_keys($components->schemas()))->toBe(['Thing', 'Thing_2'])
        // Which slot each landed in is route order; what they PUBLISH as is not. `Thing` is the class's
        // own shape whichever side registered first, and the sent shape says that it is one.
        ->and($components->schemaRenames())->toBe(['Thing' => 'ThingRequest', 'Thing_2' => 'Thing'])
        ->and($components->nameCollisions())->toBe([]);
});

it('suffixes a THIRD distinct claimant past _2 (collision ordering beyond N=2)', function (): void {
    $components = new ComponentRegistry;

    // The request phase claims the base name…
    $op = new OperationDraft;
    (new RecoveredRequest)->apply($op, requestContext($components), objectSchema(['name' => ['type' => 'string']]), 'spatie-data', 'App\\Thing');

    // …then two further distinct shapes claim it, so the suffix keeps counting rather than stopping at _2.
    $second = $components->registerSchema('Thing', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], 'App\\Other\\Thing');
    $third = $components->registerSchema('Thing', ['type' => 'object', 'properties' => ['slug' => ['type' => 'string']]], 'App\\Third\\Thing');

    expect($second)->toBe('Thing_2')
        ->and($third)->toBe('Thing_3')
        ->and(array_keys($components->schemas()))->toBe(['Thing', 'Thing_2', 'Thing_3'])
        // Re-registering an existing identity dedupes onto its own suffixed name, not a fourth.
        ->and($components->registerSchema('Thing', ['type' => 'object', 'properties' => ['slug' => ['type' => 'string']]], 'App\\Third\\Thing'))->toBe('Thing_3')
        // The request shape says it is one, so only the two response shapes contested a name — and they
        // publish off their namespaces, under ONE warning naming both.
        ->and($components->schemaRenames())->toEqual(['Thing' => 'ThingRequest', 'Thing_2' => 'OtherThing', 'Thing_3' => 'ThirdThing'])
        ->and($components->nameCollisions())->toHaveCount(1);
});

it('keeps two components when a class is used on both sides and the shapes happen to coincide', function (): void {
    // The sent shape and the returned shape are two identities, and two bodies that happen to agree
    // today are still two things an author can change independently. Collapsing them would hand the
    // survivor whichever identity registered first — route order deciding which facet the one
    // component means — so they stay apart and each publishes the name its own facet earns.
    $components = new ComponentRegistry;
    $shape = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];

    $op = new OperationDraft;
    (new RecoveredRequest)->apply($op, requestContext($components), new ValidationSchema($shape), 'spatie-data', 'App\\Thing');

    $responseName = $components->registerSchema('Thing', $shape, 'App\\Thing');

    expect($responseName)->toBe('Thing_2')
        ->and(array_keys($components->schemas()))->toBe(['Thing', 'Thing_2'])
        ->and($components->schemaIds())->toBe(['Thing' => 'App\\Thing#request', 'Thing_2' => 'App\\Thing'])
        ->and($components->schemaRenames())->toBe(['Thing' => 'ThingRequest', 'Thing_2' => 'Thing'])
        ->and($components->nameCollisions())->toBe([]);
});

it('gives a #[SchemaId]-pinned source class a pinned, rename-stable request identity', function (): void {
    $components = new ComponentRegistry;
    $schema = objectSchema(['name' => ['type' => 'string']]);

    // PinnedRequestClass carries #[SchemaId('thing.v1')]. The request identity honours the pin the way the
    // response side does — `thing.v1#request`, not `<FQCN>#request` — so it survives a class rename, and
    // the `#request` discriminator keeps it distinct from the response-side `thing.v1`.
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

/**
 * Dataset over every shape the read-verb flattener has to handle. `filter.radius_lat` in validator
 * syntax IS `filter[radius_lat]` on the wire, so a nested field is a bracketed leaf parameter — but a
 * `*` segment has no bracketed name, so that subtree stays one container parameter and gets the
 * `deepObject`/`explode` styling that documents bracketed nesting.
 */
it('expresses validated query fields as bracketed leaves, stopping at an array node', function (array $fields, array $expected): void {
    $builder = new RequestSchemaBuilder;
    foreach ($fields as $path => $spec) {
        $field = $builder->field($path);
        $field->setType($spec[0]);
        if ($spec[1]) {
            $field->markRequired();
        }
    }

    $op = new OperationDraft;
    (new RecoveredRequest)->apply(
        $op,
        requestContext(new ComponentRegistry, method: 'GET'),
        new ValidationSchema($builder->build(new RepresentationPolicy)),
        'inline-rules',
    );

    $actual = [];
    foreach ($op->freeze()->parameters as $parameter) {
        $frozen = $parameter->toArray();
        unset($frozen['x-docuccino'], $frozen['in'], $frozen['name']);
        if (is_array($frozen['schema'] ?? null)) {
            unset($frozen['schema']['x-docuccino']);
        }
        $actual[(string) $parameter->name] = $frozen;
    }

    expect($actual)->toBe($expected);
})->with([
    'flat leaf' => [
        ['q' => ['string', false]],
        ['q' => ['required' => false, 'schema' => ['type' => 'string']]],
    ],
    'one-level nested' => [
        ['filter.radius_lat' => ['number', false]],
        ['filter[radius_lat]' => ['required' => false, 'schema' => ['type' => 'number']]],
    ],
    'two-level nested' => [
        ['a.b.c' => ['string', false]],
        ['a[b][c]' => ['required' => false, 'schema' => ['type' => 'string']]],
    ],
    'per-leaf required' => [
        ['filter.a' => ['string', true], 'filter.b' => ['string', false]],
        [
            'filter[a]' => ['required' => true, 'schema' => ['type' => 'string']],
            'filter[b]' => ['required' => false, 'schema' => ['type' => 'string']],
        ],
    ],
    'array node stays one styled container' => [
        ['items.*.id' => ['integer', true]],
        ['items' => [
            'required' => false,
            'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            'style' => 'deepObject',
            'explode' => true,
        ]],
    ],
    'shapeless object stays one styled container' => [
        ['meta' => ['object', false]],
        ['meta' => ['required' => false, 'schema' => ['type' => 'object'], 'style' => 'deepObject', 'explode' => true]],
    ],
    // A list of scalars is styled too, and has to be: `form`/`explode` would document `?tags=a&tags=b`,
    // which PHP parses as the single value `b`. `deepObject` is `?tags[0]=a&tags[1]=b` — what the
    // validator actually reads.
    'scalar array stays one styled container' => [
        ['tags' => ['array', false], 'tags.*' => ['string', false]],
        ['tags' => [
            'required' => false,
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            'style' => 'deepObject',
            'explode' => true,
        ]],
    ],
]);
