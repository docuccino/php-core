<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\PropertyPresence;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\HiddenPropertyNode;
use Docuccino\Core\Tests\Fixtures\InheritingWidget;
use Docuccino\Core\Tests\Fixtures\OverridingWidget;
use Docuccino\Core\Tests\Fixtures\SelfSerialisingWidget;
use Docuccino\Core\Tests\Fixtures\WidgetBadge;
use Docuccino\Core\Tests\Fixtures\WidgetStock;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/*
 * `required` says whether the KEY is present. In a response that is what `json_encode` writes: every
 * initialised public property, null or not, and never an uninitialised one — so presence follows
 * initialisation, and each row is checked against the bytes PHP itself writes. In a request it is
 * whether the client may leave the key out, which it may wherever a default fills the value in.
 */

it('answers whether json_encode always writes the key', function (string $class, string $property, bool $expected): void {
    expect(PropertyPresence::alwaysWritten($class, $property))->toBe($expected);
})->with([
    'promoted, nullable, no default' => [WidgetBadge::class, 'icon_url', true],
    'promoted, not nullable' => [WidgetBadge::class, 'id', true],
    'promoted with a default' => [WidgetStock::class, 'colour', true],
    'promoted, not nullable, with a default' => [WidgetStock::class, 'quantity', true],
    'declared with a null default' => [WidgetStock::class, 'reorder_level', true],
    'untyped, implicitly null' => [WidgetStock::class, 'legacy', true],
    'typed, nullable, no default, not promoted' => [WidgetStock::class, 'note', false],
    // Assigned by this constructor, but nothing reflection reads says so; the caller keeps its own rule.
    'typed, no default, not promoted' => [WidgetStock::class, 'label', false],
    'static' => [WidgetStock::class, 'shared', false],
    'no such property' => [WidgetStock::class, 'missing', false],
    'promoted by a constructor the subclass inherits' => [InheritingWidget::class, 'note', true],
    'promoted by a constructor the subclass replaces' => [OverridingWidget::class, 'note', false],
    'a class stating its own JSON form' => [SelfSerialisingWidget::class, 'caption', false],
    'a class stating its own JSON form, non-null key' => [SelfSerialisingWidget::class, 'id', false],
    'no such class' => ['App\\Missing\\Widget', 'id', false],
]);

it('answers whether a default fills in a key the client leaves out', function (string $class, string $property, bool $expected): void {
    expect(PropertyPresence::defaulted($class, $property))->toBe($expected);
})->with([
    'promoted with a default' => [WidgetStock::class, 'colour', true],
    'promoted, not nullable, with a default' => [WidgetStock::class, 'quantity', true],
    'declared with a null default' => [WidgetStock::class, 'reorder_level', true],
    'untyped, implicitly null' => [WidgetStock::class, 'legacy', true],
    'promoted, no default' => [WidgetStock::class, 'id', false],
    'promoted, nullable, no default' => [WidgetBadge::class, 'icon_url', false],
    'typed, no default, not promoted' => [WidgetStock::class, 'note', false],
    'promoted by an inherited constructor, no default' => [InheritingWidget::class, 'note', false],
    'static' => [WidgetStock::class, 'shared', false],
    'no such property' => [WidgetStock::class, 'missing', false],
    'no such class' => ['App\\Missing\\Widget', 'id', false],
]);

it('states every row from what json_encode actually writes', function (): void {
    $badge = json_decode((string) json_encode(new WidgetBadge('b1', false, null)), true);
    $stock = json_decode((string) json_encode(new WidgetStock(1)), true);
    $overriding = json_decode((string) json_encode(new OverridingWidget), true);
    $inheriting = json_decode((string) json_encode(new InheritingWidget(null)), true);
    $self = json_decode((string) json_encode(new SelfSerialisingWidget(1)), true);

    expect($badge)->toBe(['id' => 'b1', 'pinned' => false, 'icon_url' => null])
        // `note` is never assigned and so never written; the rest are, null or not.
        ->and($stock)->toBe(['reorder_level' => null, 'label' => 'stock', 'legacy' => null, 'id' => 1, 'colour' => null, 'quantity' => 1])
        ->and($overriding)->toBe([])
        ->and($inheriting)->toBe(['note' => null])
        ->and($self)->toBe(['id' => 1]);
});

beforeEach(function (): void {
    /** The one component a class hoists to — keyed by its published name — its properties as the engine lists them. */
    $this->component = static function (string $class, array $properties, bool $request = false): array {
        $registry = new ComponentRegistry;
        $engine = new StubTypeEngine(classes: [$class => new ClassMetadata($class, $properties)]);
        (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry, request: $request))->toSchema(new ClassT($class));

        return $registry->schemas();
    };

    $nullableString = UnionT::of([ScalarT::string(), new NullT]);
    $this->stock = [
        new PropertyMetadata('reorder_level', $nullableString),
        new PropertyMetadata('note', $nullableString),
        new PropertyMetadata('label', ScalarT::string()),
        new PropertyMetadata('legacy', $nullableString),
        new PropertyMetadata('id', ScalarT::int()),
        new PropertyMetadata('colour', $nullableString),
        new PropertyMetadata('quantity', ScalarT::int()),
    ];
});

it('requires a nullable key a plain object always sends', function (): void {
    $schema = ($this->component)(WidgetBadge::class, [
        new PropertyMetadata('id', ScalarT::string()),
        new PropertyMetadata('pinned', ScalarT::bool()),
        new PropertyMetadata('icon_url', UnionT::of([ScalarT::string(), new NullT])),
    ])['WidgetBadge'];

    expect($schema['properties']['icon_url'])->toBe(['type' => ['string', 'null']])
        ->and($schema['required'])->toBe(['id', 'pinned', 'icon_url']);
});

it('requires what is initialised and leaves optional only a nullable key that may be unset', function (): void {
    $schema = ($this->component)(WidgetStock::class, $this->stock)['WidgetStock'];

    expect($schema['required'])->toBe(['reorder_level', 'label', 'legacy', 'id', 'colour', 'quantity']);
});

it('keeps nullable-as-optional where the class says nothing about its keys', function (string $class, string $name): void {
    $schema = ($this->component)($class, [
        new PropertyMetadata('note', UnionT::of([ScalarT::string(), new NullT])),
        new PropertyMetadata('id', ScalarT::int()),
    ])[$name];

    expect($schema['required'])->toBe(['id']);
})->with([
    'a JsonSerializable that drops a null key' => [SelfSerialisingWidget::class, 'SelfSerialisingWidget'],
    'a subclass that never runs the promoting constructor' => [OverridingWidget::class, 'OverridingWidget'],
]);

/*
 * The request half, from the contract: a key a client may leave out is optional, and the class says
 * which by what it fills in itself. `new WidgetStock(id: 1)` builds, so every defaulted key — the
 * non-nullable `quantity` too — is one a valid request omits; marking it required would call that
 * request invalid. Only `id`, which the constructor cannot do without, and `label`, which nothing can
 * be shown to fill, stay required.
 */
it('requires on a request only what no default fills in', function (): void {
    expect(new WidgetStock(id: 1))->toBeInstanceOf(WidgetStock::class);

    $schemas = ($this->component)(WidgetStock::class, $this->stock, request: true);

    expect($schemas)->toHaveCount(1)
        ->and(array_values($schemas)[0]['required'])->toBe(['label', 'id']);
});

it('publishes a request shape under the class identity qualified as a request', function (): void {
    $registry = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [WidgetStock::class => new ClassMetadata(WidgetStock::class, $this->stock)]);
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry, request: true))->toSchema(new ClassT(WidgetStock::class));
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT(WidgetStock::class));

    // Two shapes of one class, never deduped into each other; the published names settle later.
    expect(array_values($registry->schemaIds()))->toBe([WidgetStock::class.'#request', WidgetStock::class]);
});

it('keeps one component for a class whose two shapes agree', function (): void {
    $properties = [new PropertyMetadata('id', ScalarT::int()), new PropertyMetadata('internal_score', ScalarT::string())];
    $registry = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [HiddenPropertyNode::class => new ClassMetadata(HiddenPropertyNode::class, $properties)]);
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry, request: true))->toSchema(new ClassT(HiddenPropertyNode::class));
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT(HiddenPropertyNode::class));

    // Nothing is optional on one side and required on the other, so a second name would be a second
    // type in a generated client for one shape.
    expect(array_values($registry->schemaIds()))->toBe([HiddenPropertyNode::class]);
});
