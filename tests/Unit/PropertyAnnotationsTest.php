<?php

declare(strict_types=1);

use Docuccino\Attributes\Description;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\PropertyAnnotations;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\AnnotatedNode;
use Docuccino\Core\Tests\Fixtures\AttributedNode;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * Every property of the all-shapes fixture but the hidden one, as the object a mapper would hand the
 * reader — `documented` already carrying the description the docblock layer wrote, which is the write
 * the attribute has to beat.
 *
 * @return array<string, mixed>
 */
function annotatedNodeObject(): array
{
    $properties = [];
    foreach ([
        'tenant', 'settled', 'documented', 'filed', 'undescribed',
        'overdescribed', 'requestScoped', 'requestScopedFirst', 'valueless', 'twoSourced', 'named', 'twice',
    ] as $name) {
        $properties[$name] = ['type' => $name === 'settled' ? 'boolean' : 'string'];
    }

    $properties['documented']['description'] = 'Prose for whoever maintains this.';

    return ['type' => 'object', 'properties' => $properties];
}

it('writes what a property declaration says onto the member it publishes', function (string $property, array $expected): void {
    [$object] = PropertyAnnotations::apply(annotatedNodeObject(), AnnotatedNode::class);

    expect($object['properties'][$property])->toBe($expected);
})->with([
    'description and example together' => ['tenant', ['type' => 'string', 'description' => 'Who owns the invoice.', 'example' => 'acme-corp']],
    // The attribute carries a real bool, where a docblock `@example false` can only carry the word.
    'an example of the type it really is' => ['settled', ['type' => 'boolean', 'example' => false]],
    // The live inversion this closes: attribute (40) beats docblock (30) on one property.
    'the attribute over the docblock the engine recovered' => ['documented', ['type' => 'string', 'description' => 'What a consumer needs to know.']],
    // One slot, so the first declaration stands — source order, never discovery order.
    'the first of two usable examples' => ['twice', ['type' => 'string', 'example' => 'first']],
    // Repeatability made this legal PHP, and taking the first declaration and stopping would drop the
    // author's real sentence to report the misplaced one.
    'the description standing behind a declaration a schema cannot hold' => ['requestScopedFirst', ['type' => 'string', 'description' => 'What the invoice bills for.']],
]);

it('leaves a member whose declaration a property schema cannot hold as it found it', function (string $property): void {
    [$object] = PropertyAnnotations::apply(annotatedNodeObject(), AnnotatedNode::class);

    expect($object['properties'][$property])->toBe(['type' => 'string']);
})->with([
    'a #[Description(file:)], with no application root to resolve against' => 'filed',
    'a #[Description] carrying neither text nor file' => 'undescribed',
    'a #[Description] carrying both' => 'overdescribed',
    'a #[Description(request:)], which describes an operation rather than a type' => 'requestScoped',
    'an #[Example] carrying no value' => 'valueless',
    'an #[Example] carrying two' => 'twoSourced',
    'an #[Example] naming what only an Example Object holds' => 'named',
]);

it('reports every property declaration it could not publish', function (): void {
    [, $diagnostics] = PropertyAnnotations::apply(annotatedNodeObject(), AnnotatedNode::class);

    $node = AnnotatedNode::class;

    expect(array_map(static fn (Diagnostic $d): string => $d->code.': '.$d->message, $diagnostics))->toBe([
        'attribute.property-unsupported: The #[Description(file: …)] on '.$node.'::$filed says something a schema cannot hold — a property\'s description is read from the attribute itself; it was ignored.',
        'attribute.description-unusable: The #[Description] on '.$node.'::$undescribed carries neither `text:` nor `file:`; the description was not documented.',
        'attribute.description-unusable: The #[Description] on '.$node.'::$overdescribed carries both `text:` and `file:`; the description was not documented.',
        'attribute.property-unsupported: The #[Description(request: true)] on '.$node.'::$requestScoped says something a schema cannot hold — a request body is one operation\'s use of a type, and a property\'s description describes the type itself; it was ignored.',
        'attribute.property-unsupported: The #[Description(request: true)] on '.$node.'::$requestScopedFirst says something a schema cannot hold — a request body is one operation\'s use of a type, and a property\'s description describes the type itself; it was ignored.',
        'attribute.example-unusable: An #[Example] on '.$node.'::$valueless carries no value — give it a `value:`; it was not documented.',
        'attribute.example-unusable: An #[Example] on '.$node.'::$twoSourced carries more than one value — `value:`, `file:` and `externalValue:` are alternatives; it was not documented.',
        'attribute.property-unsupported: The #[Example] on '.$node.'::$named says something a property schema cannot hold — a property publishes one bare example value, which carries no `name:`, no `summary:`, no `status:`; it was ignored.',
    ]);
});

it('names an anonymous class by where it stands, not by where the build machine keeps it', function (): void {
    // `::class` on an anonymous class is the base name, a NUL byte, the ABSOLUTE file it was written in
    // and a counter of the anonymous classes this process declared first. A mapper takes whatever name
    // its caller was handed, and these diagnostics are embedded in the document — so raw, the site puts
    // the build machine into the output and makes two runs over one tree disagree.
    $subject = new class
    {
        #[Description(file: 'docs/tenant.md')]
        public string $filed = '';
    };

    [, $diagnostics] = PropertyAnnotations::apply(
        ['type' => 'object', 'properties' => ['filed' => ['type' => 'string']]],
        $subject::class,
    );

    $message = $diagnostics[0]->message ?? '';

    expect($message)->toContain('class@anonymous declared in tests/Unit/PropertyAnnotationsTest.php:')
        ->and($message)->toContain('::$filed')
        // No NUL byte, no absolute prefix, and no process-order counter.
        ->and($message)->not->toContain("\0")
        ->and($message)->not->toContain(dirname(__DIR__, 3))
        ->and($message)->not->toMatch('/\$[0-9a-f]+::/');
});

it('says nothing about a property the schema does not publish', function (): void {
    // `unpublished` carries a good #[Description] and a reportable #[Example], and the schema hides it.
    // There is no member to write and nothing the reader could do, so neither the write nor the
    // complaint happens.
    [$object, $diagnostics] = PropertyAnnotations::apply(annotatedNodeObject(), AnnotatedNode::class);

    expect($object['properties'])->not->toHaveKey('unpublished')
        ->and(array_filter($diagnostics, static fn (Diagnostic $d): bool => str_contains($d->message, 'unpublished')))->toBe([]);
});

it('follows a property to the key it publishes under', function (): void {
    // A mapper whose wire names differ from its properties — spatie's #[MapName] — hands over the map,
    // and the declaration lands on the published key rather than the PHP one.
    $object = ['type' => 'object', 'properties' => ['owning_tenant' => ['type' => 'string']]];

    [$applied] = PropertyAnnotations::apply($object, AnnotatedNode::class, ['tenant' => 'owning_tenant']);

    expect($applied['properties']['owning_tenant'])
        ->toBe(['type' => 'string', 'description' => 'Who owns the invoice.', 'example' => 'acme-corp']);
});

it('leaves a class with no property declarations byte-identical', function (): void {
    $object = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

    expect(PropertyAnnotations::apply($object, AttributedNode::class))->toBe([$object, []]);
});

it('degrades to the untouched object for a class that cannot be loaded', function (): void {
    $object = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

    expect(PropertyAnnotations::apply($object, 'App\\Nope\\Missing'))->toBe([$object, []]);
});

it('leaves an object with no properties alone', function (): void {
    expect(PropertyAnnotations::apply(['type' => 'object'], AnnotatedNode::class))->toBe([['type' => 'object'], []]);
});

it('publishes the declarations on the hoisted component the fallback mapper builds', function (): void {
    // End to end through the chain a plain DTO reaches: the engine recovers the docblock prose into the
    // property's `description`, and the attribute overwrites it on the component that ships.
    $fqcn = AnnotatedNode::class;
    $engine = new StubTypeEngine(classes: [
        $fqcn => new ClassMetadata($fqcn, [
            new PropertyMetadata('tenant', ScalarT::string()),
            new PropertyMetadata('documented', ScalarT::string(), summary: 'Prose for whoever maintains this.'),
        ]),
    ]);

    $registry = new ComponentRegistry;
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT($fqcn));

    expect($registry->schemas()['AnnotatedNode']['properties'])->toBe([
        'tenant' => ['type' => 'string', 'description' => 'Who owns the invoice.', 'example' => 'acme-corp'],
        'documented' => ['type' => 'string', 'description' => 'What a consumer needs to know.'],
    ]);

    // The reader's complaints reach the build through the schema context, not a swallowed return.
    expect(array_map(static fn (Diagnostic $d): string => $d->code, $registry->diagnostics()))->toBe([]);
});
