<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteDependencies;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\EnumComponent;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Tests\Fixtures\DescribedStatus;
use Docuccino\Core\Tests\Fixtures\FiledStatus;
use Docuccino\Core\Tests\Fixtures\OverdescribedStatus;
use Docuccino\Core\Tests\Fixtures\RequestScopedStatus;
use Docuccino\Core\Tests\Fixtures\SampleStatus;

/**
 * What an ENUM publishes about itself. An enum is a class, so PHP accepts a class-target
 * `#[Description]` on one and `SchemaClassAttributes::HONOURED` promises every schema class is read for
 * it as "the schema description" — while OpenAPI holds `description` on any Schema Object, an
 * `enum`-bearing one included. An enum reaches a component through its own producer rather than through
 * `ComponentHoist`, so that sentence used to reach nothing and report nothing: the one silence the
 * honoured table exists to make impossible.
 */
$convert = static function (string $fqcn, ComponentRegistry $components, bool $enumComponents = true): array {
    // SORTED, with EnumSchema in front, as the pipeline builds the chain: the reflection-rich enum
    // mapper only supersedes the case-names-only one by running earlier.
    $converter = new SchemaConverter(
        (new ExtensionSorter)->sort([new EnumSchema, ...DefaultTypeMappers::all()]),
        new NullTypeEngine,
        $components,
        new RepresentationPolicy(enumComponents: $enumComponents),
    );

    return $converter->toSchema(new EnumT($fqcn))->schema;
};

it('publishes the sentence an enum states about itself', function () use ($convert): void {
    $components = new ComponentRegistry;

    expect($convert(DescribedStatus::class, $components))->toBe(['$ref' => '#/components/schemas/DescribedStatus'])
        ->and($components->schemas()['DescribedStatus']['description'] ?? null)
        ->toBe('How far through review a submission has got.');
});

it('says the same thing inline as it says in the component', function () use ($convert): void {
    // With `enums.components` off the very same body is published in place, so the two forms of one
    // enum cannot say different things about it: the inline schema is the component minus its identity.
    $inline = $convert(DescribedStatus::class, new ComponentRegistry, enumComponents: false);

    $components = new ComponentRegistry;
    $convert(DescribedStatus::class, $components);
    $hoisted = $components->schemas()['DescribedStatus'];
    unset($hoisted['x-docuccino']);

    expect($inline)->toBe($hoisted)
        ->and($inline['description'])->toBe('How far through review a submission has got.');
});

it('publishes no description for an enum that states none', function () use ($convert): void {
    $components = new ComponentRegistry;
    $convert(SampleStatus::class, $components);

    expect($components->schemas()['SampleStatus'])->not->toHaveKey('description');
});

it('leaves an enum it cannot reflect exactly as it was', function (): void {
    // Nothing to read a declaration off, so the answer stays the one the DType alone supports.
    $converter = new SchemaConverter(
        (new ExtensionSorter)->sort([new EnumSchema, ...DefaultTypeMappers::all()]),
        new NullTypeEngine,
        new ComponentRegistry,
    );

    expect($converter->toSchema(new EnumT('App\\Nope\\Missing', ['draft', 'live']))->schema)->toBe([
        'type' => 'string',
        'enum' => ['draft', 'live'],
        'x-enum-varnames' => ['draft', 'live'],
        'x-enumNames' => ['draft', 'live'],
    ]);
});

it('refuses a declaration on an enum exactly as it refuses one on any other class', function (string $fqcn, string $expected) use ($convert): void {
    // The refusal is DescribedText's, stated once for every reader, so an enum earns the sentence a DTO
    // earns rather than a second dialect of it — every branch of that table, because "the same code runs"
    // is a claim about the enum path rather than a reading of it. A row per refusal is what would notice
    // the enum path growing a read of its own.
    $components = new ComponentRegistry;
    $convert($fqcn, $components);

    expect($components->schemas()[(new ReflectionEnum($fqcn))->getShortName()])->not->toHaveKey('description')
        ->and(array_map(static fn (Diagnostic $d): string => $d->code.': '.$d->message, $components->diagnostics()))
        ->toBe([$expected]);
})->with([
    'both halves of the declaration' => [
        OverdescribedStatus::class,
        'attribute.description-unusable: The #[Description] on '.OverdescribedStatus::class.' carries both `text:` and `file:`; the description was not documented.',
    ],
    'a file, with no application root to resolve it against' => [
        FiledStatus::class,
        'attribute.property-unsupported: The #[Description(file: …)] on '.FiledStatus::class.' says something a schema cannot hold — a schema\'s description is read from the attribute itself; it was ignored.',
    ],
    'a request body, which is one operation\'s use of the type rather than the type' => [
        RequestScopedStatus::class,
        'attribute.property-unsupported: The #[Description(request: true)] on '.RequestScopedStatus::class.' says something a schema cannot hold — a request body is one operation\'s use of a type, and a schema\'s description describes the type itself; it was ignored.',
    ],
]);

it('records the enum\'s declaration for whichever producer asked what it says', function (string $ask): void {
    // Asking is what registers the dependency, so a producer added later cannot forget it. Two ways to
    // ask — a whole body, or the sentence alone for a field publishing the set inline — and a fragment
    // keyed without this file replays the answer given before the sentence was written.
    $dependencies = new RouteDependencies;
    $converter = new SchemaConverter(
        DefaultTypeMappers::all(),
        new NullTypeEngine,
        new ComponentRegistry,
        new RepresentationPolicy,
        $dependencies,
    );

    $ask === 'body'
        ? EnumComponent::body(DescribedStatus::class, $converter)
        : EnumComponent::description(DescribedStatus::class, $converter);

    expect($dependencies->files())->toBe([(string) (new ReflectionEnum(DescribedStatus::class))->getFileName()]);
})->with(['body', 'description']);
