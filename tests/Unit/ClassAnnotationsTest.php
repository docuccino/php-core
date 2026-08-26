<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ClassAnnotations;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\DescribedNode;
use Docuccino\Core\Tests\Fixtures\FiledNode;
use Docuccino\Core\Tests\Fixtures\InheritingNode;
use Docuccino\Core\Tests\Fixtures\OverdescribedNode;
use Docuccino\Core\Tests\Fixtures\RequestScopedFirstNode;
use Docuccino\Core\Tests\Fixtures\RequestScopedNode;
use Docuccino\Core\Tests\Fixtures\UndescribedNode;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * What a class publishes about ITSELF. Every schema in a document used to be undescribed, because the
 * only prose about a class was its docblock — which is written for whoever maintains the class and
 * routinely names properties, attributes and internals a consumer cannot see. `#[Description]` is the
 * unambiguous form, so it is the one that publishes.
 */
$object = static fn (): array => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

it('publishes the sentence a class states about itself', function () use ($object): void {
    [$schema, $diagnostics] = ClassAnnotations::describe($object(), DescribedNode::class);

    expect($schema['description'])->toBe('A single retention policy, as the billing system holds it.')
        ->and($diagnostics)->toBe([]);
});

it('publishes the sentence standing behind a declaration a schema cannot hold', function () use ($object): void {
    // Repeatability made this legal PHP, and taking the first declaration and stopping would drop the
    // author's real sentence to report the misplaced one.
    [$schema, $diagnostics] = ClassAnnotations::describe($object(), RequestScopedFirstNode::class);

    expect($schema['description'])->toBe('A retention policy the billing system accepts.')
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['attribute.property-unsupported']);
});

it('leaves the class untouched where the declaration says nothing publishable', function (string $fqcn) use ($object): void {
    [$schema] = ClassAnnotations::describe($object(), $fqcn);

    expect($schema)->toBe($object());
})->with([
    // A parent's sentence describes the parent; inheriting it would put one description on every shape
    // below a shared base.
    'a subclass of a described class' => InheritingNode::class,
    'a #[Description(file:)], with no application root to resolve against' => FiledNode::class,
    'a #[Description] carrying both text and file' => OverdescribedNode::class,
    'a #[Description] carrying neither' => UndescribedNode::class,
    'a #[Description(request:)], which describes an operation rather than a type' => RequestScopedNode::class,
    'a class carrying no declaration at all' => ClassMetadata::class,
    'a class that cannot be loaded' => 'App\\Nope\\Missing',
]);

it('reports a class declaration it could not publish', function (string $fqcn, string $expected) use ($object): void {
    [, $diagnostics] = ClassAnnotations::describe($object(), $fqcn);

    expect(array_map(static fn (Diagnostic $d): string => $d->code.': '.$d->message, $diagnostics))->toBe([$expected]);
})->with([
    'file, with nothing to resolve it against' => [
        FiledNode::class,
        'attribute.property-unsupported: The #[Description(file: …)] on '.FiledNode::class.' says something a schema cannot hold — a schema\'s description is read from the attribute itself; it was ignored.',
    ],
    'both halves' => [
        OverdescribedNode::class,
        'attribute.description-unusable: The #[Description] on '.OverdescribedNode::class.' carries both `text:` and `file:`; the description was not documented.',
    ],
    'neither half' => [
        UndescribedNode::class,
        'attribute.description-unusable: The #[Description] on '.UndescribedNode::class.' carries neither `text:` nor `file:`; the description was not documented.',
    ],
    'request, which is an operation\'s use of the type rather than the type' => [
        RequestScopedNode::class,
        'attribute.property-unsupported: The #[Description(request: true)] on '.RequestScopedNode::class.' says something a schema cannot hold — a request body is one operation\'s use of a type, and a schema\'s description describes the type itself; it was ignored.',
    ],
]);

it('keeps a description the mapper already wrote', function (): void {
    // The class-level sentence is the coarsest thing anyone can say about the shape, so it never
    // overwrites one a mapper built from something it knows more about.
    [$schema] = ClassAnnotations::describe(['type' => 'object', 'description' => 'What the mapper knew.'], DescribedNode::class);

    expect($schema['description'])->toBe('What the mapper knew.');
});

it('describes the component every hoisting mapper lifts, from one place', function (): void {
    // The write lives in ComponentHoist, so a class reaches it through whichever mapper claimed the type
    // rather than through a per-mapper copy of the same read.
    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        DescribedNode::class => new ClassMetadata(DescribedNode::class, [new PropertyMetadata('id', ScalarT::int())]),
    ]);

    $result = (new SchemaConverter(DefaultTypeMappers::all(), $engine, $components))->toSchema(new ClassT(DescribedNode::class));

    expect($result->schema)->toBe(['$ref' => '#/components/schemas/DescribedNode'])
        ->and($components->schemas()['DescribedNode']['description'])
        ->toBe('A single retention policy, as the billing system holds it.');
});

it('describes a class whose body could not be analysed at all', function (): void {
    // A shape that would not expand is exactly where the one sentence its author wrote is worth most: the
    // degraded object still says what the thing IS.
    $result = (new SchemaConverter(DefaultTypeMappers::all(), new StubTypeEngine, new ComponentRegistry))
        ->toSchema(new ClassT(DescribedNode::class));

    expect($result->schema)->toBe([
        'type' => 'object',
        'description' => 'A single retention policy, as the billing system holds it.',
    ]);
});
