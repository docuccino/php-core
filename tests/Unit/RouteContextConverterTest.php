<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeSchemaConverter;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * What `RouteContext::converter()` promises an extension author: a {@see TypeSchemaConverter}, which is
 * the same object mappers are handed as their {@see SchemaContext}, with a confidence run and a depth
 * count that start at each top-level {@see TypeSchemaConverter::toSchema()}.
 */
$context = static fn (TypeToSchema ...$mappers): RouteContext => new RouteContext(
    route: new RouteDescriptor(['GET'], 'api/gadgets'),
    actionRef: new ActionRef('app/Http/GadgetController.php', 'App\\Http\\GadgetController', 'index'),
    attributes: new AttributeSet,
    engine: new StubTypeEngine,
    document: new DocumentConfig('default', []),
    extensions: new ResolvedExtensions(
        typeToSchema: array_values($mappers),
    ),
);

it('hands out the public contract, memoised for the route', function () use ($context): void {
    // Memoised because the confidence run and the depth count are instance state: a fresh converter per
    // call would report a nested conversion as if it were a top-level one.
    $route = $context();

    expect($route->converter())->toBeInstanceOf(TypeSchemaConverter::class)
        ->and($route->converter())->toBe($route->converter());
});

it('is the SchemaContext mappers receive, so it passes straight to a rules converter', function () use ($context): void {
    $mapper = new class implements TypeToSchema
    {
        public ?SchemaContext $seen = null;

        public function supports(DType $type): bool
        {
            return true;
        }

        public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
        {
            $this->seen = $context;

            return new SchemaResult(['type' => 'string']);
        }
    };

    $route = $context($mapper);
    $route->converter()->toSchema(ScalarT::string());

    expect($mapper->seen)->toBe($route->converter());
});

it('starts a fresh confidence run at each top-level conversion', function () use ($context): void {
    $mapper = new class implements TypeToSchema
    {
        public float $confidence = 1.0;

        public function supports(DType $type): bool
        {
            return true;
        }

        public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
        {
            return new SchemaResult(['type' => 'string'], $this->confidence);
        }
    };

    $converter = $context($mapper)->converter();

    $mapper->confidence = 0.2;
    $imprecise = $converter->toSchema(ScalarT::string());

    $mapper->confidence = 1.0;
    $precise = $converter->toSchema(ScalarT::string());

    expect($imprecise->confidence)->toBe(0.2)
        ->and($precise->confidence)->toBe(1.0);
});

it('keeps the lowest confidence a nested conversion reported, and counts depth from the top', function () use ($context): void {
    $mapper = new class implements TypeToSchema
    {
        /** @var list<int> */
        public array $depths = [];

        public function supports(DType $type): bool
        {
            return true;
        }

        public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
        {
            $this->depths[] = $context->depth();

            if ($type instanceof ListT) {
                return new SchemaResult(['type' => 'array', 'items' => $context->convert($type->value)]);
            }

            return new SchemaResult(['type' => 'string'], 0.3);
        }
    };

    $result = $context($mapper)->converter()->toSchema(new ListT(ScalarT::string()));

    expect($result->confidence)->toBe(0.3)
        ->and($mapper->depths)->toBe([1, 2]);
});
