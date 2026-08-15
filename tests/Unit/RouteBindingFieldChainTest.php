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
 * The binding-COLUMN chain, which is its own chain and not a view over the route-KEY one. Two rules make
 * it safe: the key chain is never consulted for a column, and a column nothing answers for degrades to
 * null — never to the route key's schema, which for a `{post:slug}` would be a confidently wrong
 * `integer`.
 */
$context = static function (array $keyResolvers, array $fieldResolvers): RouteContext {
    return new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/posts/{post}'),
        actionRef: new ActionRef('app/Http/PostController.php', 'App\\Http\\PostController', 'show'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
        routeBindingSchemaResolvers: $keyResolvers,
        routeBindingFieldSchemaResolvers: $fieldResolvers,
    );
};

$keyOnly = new class implements RouteBindingSchemaResolver
{
    public int $asked = 0;

    public function keySchemaFor(string $modelFqcn): ?array
    {
        $this->asked++;

        return ['type' => 'integer'];
    }
};

$declines = new class implements RouteBindingFieldSchemaResolver
{
    /** @var list<string> */
    public array $asked = [];

    public function fieldSchemaFor(RouteContext $context, string $modelFqcn, string $field): ?array
    {
        $this->asked[] = $modelFqcn.'::'.$field;

        return null;
    }
};

$fieldAware = new class implements RouteBindingFieldSchemaResolver
{
    /** @var list<string> */
    public array $asked = [];

    public function fieldSchemaFor(RouteContext $context, string $modelFqcn, string $field): ?array
    {
        $this->asked[] = $modelFqcn.'::'.$field;

        return $field === 'slug' ? ['type' => 'string'] : null;
    }
};

it('takes the first column answer in the chain and never asks the key chain', function () use ($context, $keyOnly, $declines, $fieldAware): void {
    $keyOnly->asked = 0;
    $declines->asked = [];
    $fieldAware->asked = [];

    expect($context([$keyOnly], [$declines, $fieldAware])->routeBindingFieldSchema('App\\Models\\Post', 'slug'))
        ->toBe(['type' => 'string'])
        // A decline passes the question on rather than ending the chain…
        ->and($declines->asked)->toBe(['App\\Models\\Post::slug'])
        ->and($fieldAware->asked)->toBe(['App\\Models\\Post::slug'])
        // …and the key resolver is never asked a question it has no answer to.
        ->and($keyOnly->asked)->toBe(0);
});

it('degrades to null rather than to the route key', function (string $chain, string $field) use ($context, $keyOnly, $fieldAware): void {
    // Every one of these has a route-key schema sitting right there, and answering with it is exactly
    // the wrong type this chain exists to avoid.
    $fieldResolvers = $chain === 'field-aware' ? [$fieldAware] : [];

    $route = $context([$keyOnly], $fieldResolvers);

    expect($route->routeBindingFieldSchema('App\\Models\\Post', $field))->toBeNull()
        // …while the key chain, which the key resolver does answer, is untouched.
        ->and($route->routeBindingKeySchema('App\\Models\\Post'))->toBe(['type' => 'integer']);
})->with([
    'a field-aware resolver that cannot type this column' => ['field-aware', 'reference'],
    'no field resolver contributed at all' => ['none', 'slug'],
]);

it('answers nothing for either binding question when no resolver is contributed', function () use ($context): void {
    $route = $context([], []);

    expect($route->routeBindingFieldSchema('App\\Models\\Post', 'slug'))->toBeNull()
        ->and($route->routeBindingKeySchema('App\\Models\\Post'))->toBeNull();
});
