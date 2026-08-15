<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * The binding-COLUMN chain, which shares its resolver list with the route-KEY chain. Two rules make it
 * safe: a resolver that only knows the route key is skipped rather than asked the wrong question, and a
 * column nothing answers for degrades to null — never to the route key's schema, which for a
 * `{post:slug}` would be a confidently wrong `integer`.
 */
$context = static function (array $resolvers): RouteContext {
    return new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/posts/{post}'),
        actionRef: new ActionRef('app/Http/PostController.php', 'App\\Http\\PostController', 'show'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
        routeBindingSchemaResolvers: $resolvers,
    );
};

$keyOnly = new class implements RouteBindingSchemaResolver
{
    public function keySchemaFor(string $modelFqcn): ?array
    {
        return ['type' => 'integer'];
    }
};

$fieldAware = new class implements RouteBindingFieldSchemaResolver
{
    /** @var list<string> */
    public array $asked = [];

    public function keySchemaFor(string $modelFqcn): ?array
    {
        return ['type' => 'integer'];
    }

    public function fieldSchemaFor(RouteContext $context, string $modelFqcn, string $field): ?array
    {
        $this->asked[] = $modelFqcn.'::'.$field;

        return $field === 'slug' ? ['type' => 'string'] : null;
    }
};

it('takes the first column answer in the chain, skipping resolvers that only know the key', function () use ($context, $keyOnly, $fieldAware): void {
    $fieldAware->asked = [];

    expect($context([$keyOnly, $fieldAware])->routeBindingFieldSchema('App\\Models\\Post', 'slug'))
        ->toBe(['type' => 'string'])
        // The key-only resolver was never asked a question it has no answer to.
        ->and($fieldAware->asked)->toBe(['App\\Models\\Post::slug']);
});

it('degrades to null rather than to the route key', function (string $chain, string $field) use ($context, $keyOnly, $fieldAware): void {
    // Every one of these has a route-key schema sitting right there, and answering with it is exactly
    // the wrong type this chain exists to avoid.
    $resolvers = match ($chain) {
        'key-only' => [$keyOnly],
        'field-aware' => [$fieldAware],
        default => [],
    };

    $route = $context($resolvers);

    expect($route->routeBindingFieldSchema('App\\Models\\Post', $field))->toBeNull()
        // …while the key chain, which those resolvers do answer, is untouched.
        ->and($route->routeBindingKeySchema('App\\Models\\Post'))
        ->toBe($resolvers === [] ? null : ['type' => 'integer']);
})->with([
    'a resolver that only knows the route key' => ['key-only', 'slug'],
    'a field-aware resolver that cannot type this column' => ['field-aware', 'reference'],
    'no resolver contributed at all' => ['none', 'slug'],
]);
