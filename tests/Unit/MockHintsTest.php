<?php

declare(strict_types=1);

use Docuccino\Attributes\Mock;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\AttributedNode;
use Docuccino\Core\Tests\Fixtures\MockedNode;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * @param  list<string>  $properties
 * @return array<string, mixed>
 */
function stringObject(array $properties): array
{
    return [
        'type' => 'object',
        'properties' => array_combine($properties, array_fill(0, count($properties), ['type' => 'string'])),
    ];
}

/** Every property of the all-shapes fixture, as the object a mapper would hand the reader. */
function mockedNodeObject(): array
{
    return stringObject(['id', 'reference', 'email', 'name', 'blank', 'empty', 'misdirected']);
}

it('writes each #[Mock] parameter onto the property it names', function (string $property, array $expected): void {
    [$object] = MockHints::apply(mockedNodeObject(), MockedNode::class);

    expect($object['properties'][$property]['x-docuccino']['mock'])->toBe($expected);
})->with([
    // The class-level form, which is what an Eloquent column or a validated field can reach.
    'faker, named from the class' => ['id', ['faker' => 'uuid']],
    // Both parameters at once, in canonical order.
    'faker + seedGroup' => ['email', ['faker' => 'safeEmail', 'seedGroup' => 'person']],
    // seedGroup alone is a complete hint: it correlates a value it does not describe.
    'seedGroup alone' => ['name', ['seedGroup' => 'person']],
    // The property's own attribute beats a class-level one naming it — the specific wins, as everywhere.
    'the property beats the class-level claim' => ['reference', ['faker' => 'slug']],
    // A stray `property` is diagnosed, but the hint still lands where the attribute is written.
    'a property-level one that names a property anyway' => ['misdirected', ['faker' => 'colorName']],
]);

it('leaves a property no #[Mock] names untouched', function (string $property): void {
    [$object] = MockHints::apply(mockedNodeObject(), MockedNode::class);

    expect($object['properties'][$property])->toBe(['type' => 'string']);
})->with([
    // Whitespace is not an expression; trimming it away leaves the attribute saying nothing.
    'a whitespace-only faker' => 'blank',
    'an attribute carrying neither parameter' => 'empty',
]);

it('reports every #[Mock] that cannot publish anything', function (): void {
    [, $diagnostics] = MockHints::apply(mockedNodeObject(), MockedNode::class);

    $messages = array_map(static fn (Diagnostic $d): string => $d->code.': '.$d->message, $diagnostics);

    expect($messages)->toBe([
        'attribute.mock-invalid: #[Mock] on class '.MockedNode::class.' names no property; it is ignored.',
        'attribute.mock-invalid: #[Mock(property: \'id\')] on class '.MockedNode::class.' carries no faker expression and no seed group; it is ignored.',
        'attribute.mock-unknown-property: #[Mock(property: \'not_a_property\')] on class '.MockedNode::class.' names a property the schema does not publish; the hint is dropped.',
        'attribute.mock-invalid: #[Mock] on '.MockedNode::class.'::$blank carries no faker expression and no seed group; it is ignored.',
        'attribute.mock-invalid: #[Mock] on '.MockedNode::class.'::$empty carries no faker expression and no seed group; it is ignored.',
        'attribute.mock-invalid: #[Mock] on '.MockedNode::class.'::$misdirected names a property, which only a class-level one needs; it is ignored.',
    ]);
});

it('names an anonymous class by where it stands, not by where the build machine keeps it', function (): void {
    // Same reader, same argument, same hardening as PropertyAnnotations beside it: `::class` on an
    // anonymous class is the base name, a NUL byte, the ABSOLUTE file it was written in and a counter of
    // the anonymous classes this process declared first. These diagnostics are embedded in the document,
    // so raw the site puts the build machine into the output and makes two runs over one tree disagree.
    $subject = new class
    {
        #[Mock]
        public string $empty = '';
    };

    [, $diagnostics] = MockHints::apply(stringObject(['empty']), $subject::class);

    $message = $diagnostics[0]->message ?? '';

    expect($message)->toContain('class@anonymous declared in tests/Unit/MockHintsTest.php:')
        ->and($message)->toContain('::$empty')
        ->and($message)->not->toContain("\0")
        ->and($message)->not->toContain(dirname(__DIR__, 3))
        ->and($message)->not->toMatch('/\$[0-9a-f]+::/');
});

it('drops a class-level hint naming a property the schema hides, and says so', function (): void {
    // The property exists but `#[Hidden]` keeps it out, so there is nothing left to carry the hint —
    // and the reader can act either way: unhide it, or delete the #[Mock].
    $object = stringObject(['id']);

    [$applied, $diagnostics] = MockHints::apply($object, MockedNode::class);

    expect($applied['properties'])->not->toHaveKey('not_a_property')
        ->and(array_column(array_map(static fn (Diagnostic $d): array => $d->toArray(), $diagnostics), 'code'))
        ->toContain('attribute.mock-unknown-property');
});

it('leaves a class with no #[Mock] byte-identical', function (): void {
    $object = stringObject(['id']);

    expect(MockHints::apply($object, AttributedNode::class))->toBe([$object, []]);
});

it('degrades to the untouched object for a class that cannot be loaded', function (): void {
    // Same total contract as SchemaIdentity: an unloadable FQCN answers "nothing", never throws.
    $object = stringObject(['id']);

    expect(MockHints::apply($object, 'App\\Nope\\Missing'))->toBe([$object, []])
        ->and(SchemaIdentity::hidden('App\\Nope\\Missing'))->toBe([]);
});

it('leaves an object with no properties alone', function (): void {
    expect(MockHints::apply(['type' => 'object'], MockedNode::class)[0])->toBe(['type' => 'object']);
});

it('publishes the hints on the hoisted component the fallback mapper builds', function (): void {
    // End to end through the chain a plain DTO reaches, so the hint is proved to survive hoisting and
    // to sit under `x-docuccino` rather than as a schema keyword.
    $fqcn = MockedNode::class;
    $engine = new StubTypeEngine(classes: [
        $fqcn => new ClassMetadata($fqcn, [
            new PropertyMetadata('id', ScalarT::string()),
            new PropertyMetadata('email', ScalarT::string()),
        ]),
    ]);

    $registry = new ComponentRegistry;
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT($fqcn));

    expect($registry->schemas()['MockedNode']['properties'])->toBe([
        'id' => ['type' => 'string', 'x-docuccino' => ['mock' => ['faker' => 'uuid']]],
        'email' => ['type' => 'string', 'x-docuccino' => ['mock' => ['faker' => 'safeEmail', 'seedGroup' => 'person']]],
    ]);

    // The reader's complaints reach the build through the schema context, not a swallowed return.
    expect(array_map(static fn (Diagnostic $d): string => $d->code, $registry->diagnostics()))
        ->toContain('attribute.mock-invalid', 'attribute.mock-unknown-property');
});
