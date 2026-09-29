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
use Docuccino\Core\Tests\Fixtures\ProblemDetails;
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

it('answers whether json_encode writes the key', function (string $class, string $property, ?bool $initialised, ?bool $expected): void {
    expect(PropertyPresence::written($class, $property, $initialised))->toBe($expected);
})->with([
    'promoted, nullable, no default' => [WidgetBadge::class, 'icon_url', null, true],
    'promoted, not nullable' => [WidgetBadge::class, 'id', null, true],
    'promoted with a default' => [WidgetStock::class, 'colour', null, true],
    'promoted, not nullable, with a default' => [WidgetStock::class, 'quantity', null, true],
    'declared with a null default' => [WidgetStock::class, 'reorder_level', null, true],
    'untyped, implicitly null' => [WidgetStock::class, 'legacy', null, true],
    // Typed, no default, not promoted: reflection cannot tell, so the engine's reading of the constructor does.
    'typed, nullable, nothing proved' => [WidgetStock::class, 'note', null, null],
    'typed, nothing proved' => [WidgetStock::class, 'label', null, null],
    'typed, assigned on every constructor path' => [ProblemDetails::class, 'title', true, true],
    'typed, nullable, assigned on every constructor path' => [ProblemDetails::class, 'instance', true, true],
    'typed, assigned on only some constructor paths' => [ProblemDetails::class, 'detail', false, false],
    'readonly, assigned on only some constructor paths' => [ProblemDetails::class, 'traceId', false, false],
    // What reflection proves is not the engine's to contradict.
    'promoted, whatever the engine says' => [WidgetBadge::class, 'id', false, true],
    'defaulted, whatever the engine says' => [WidgetStock::class, 'quantity', false, true],
    'static' => [WidgetStock::class, 'shared', true, null],
    'no such property' => [WidgetStock::class, 'missing', true, null],
    'promoted by a constructor the subclass inherits' => [InheritingWidget::class, 'note', null, true],
    'promoted by a constructor the subclass replaces' => [OverridingWidget::class, 'note', null, null],
    'a class stating its own JSON form' => [SelfSerialisingWidget::class, 'caption', null, null],
    'a class stating its own JSON form, whatever the engine says' => [SelfSerialisingWidget::class, 'id', false, null],
    'no such class' => ['App\\Missing\\Widget', 'id', true, null],
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
    $bare = json_decode((string) json_encode(new ProblemDetails(404, 'Not Found')), true);
    $full = json_decode((string) json_encode(new ProblemDetails(409, 'Conflict', 'It moved.', '/orders/7', 't-1')), true);

    expect($badge)->toBe(['id' => 'b1', 'pinned' => false, 'icon_url' => null])
        // `note` is never assigned and so never written; the rest are, null or not.
        ->and($stock)->toBe(['reorder_level' => null, 'label' => 'stock', 'legacy' => null, 'id' => 1, 'colour' => null, 'quantity' => 1])
        ->and($overriding)->toBe([])
        ->and($inheriting)->toBe(['note' => null])
        ->and($self)->toBe(['id' => 1])
        // A constructor branch not taken leaves its property unset, readonly or not, and the key goes with it;
        // `instance` is assigned on every path, so it is written even as null.
        ->and($bare)->toBe(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'instance' => null])
        ->and(array_keys($full))->toBe(['type', 'title', 'status', 'detail', 'instance', 'traceId']);
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

    /** ProblemDetails as the engine lists it, each property carrying the given constructor fact. */
    $this->problem = static fn (array $initialised): array => [
        new PropertyMetadata('type', ScalarT::string(), initialised: $initialised['type'] ?? null),
        new PropertyMetadata('title', ScalarT::string(), initialised: $initialised['title'] ?? null),
        new PropertyMetadata('status', ScalarT::int(), initialised: $initialised['status'] ?? null),
        new PropertyMetadata('detail', ScalarT::string(), initialised: $initialised['detail'] ?? null),
        new PropertyMetadata('instance', $nullableString, initialised: $initialised['instance'] ?? null),
        new PropertyMetadata('traceId', ScalarT::string(), initialised: $initialised['traceId'] ?? null),
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
 * A typed property with no default that is not promoted exists on the wire exactly when the constructor
 * assigned it: `new ProblemDetails(404, 'Not Found')` sends no `detail` and no `traceId` (the row above
 * checks the bytes), so publishing either as required calls that response invalid. `instance` is assigned
 * on every path, so its key is always sent, `null` included.
 */
it('publishes what the constructor assigns on every path as required, and what it may skip as optional', function (): void {
    $schema = ($this->component)(ProblemDetails::class, ($this->problem)(['type' => true, 'title' => true, 'status' => true, 'detail' => false, 'instance' => true, 'traceId' => false]))['ProblemDetails'];

    expect($schema['required'])->toBe(['type', 'title', 'status', 'instance'])
        // Optional is not nullable: the server never sends `null` for either.
        ->and($schema['properties']['detail'])->toBe(['type' => 'string'])
        ->and($schema['properties']['traceId'])->toBe(['type' => 'string']);
});

it('keeps the nullability stand-in where nothing proved how the constructor assigns', function (): void {
    $schema = ($this->component)(ProblemDetails::class, ($this->problem)([]))['ProblemDetails'];

    expect($schema['required'])->toBe(['type', 'title', 'status', 'detail', 'traceId']);
});

it('reads nothing into a constructor of a class that states its own keys', function (): void {
    $schema = ($this->component)(SelfSerialisingWidget::class, [
        new PropertyMetadata('id', ScalarT::int(), initialised: false),
        new PropertyMetadata('caption', UnionT::of([ScalarT::string(), new NullT]), initialised: true),
    ])['SelfSerialisingWidget'];

    expect($schema['required'])->toBe(['id']);
});

/*
 * Whether a client may leave a key out is decided by what fills it in when they do, and the paths the
 * server's constructor takes are no part of what a client sends — so the request shape is the one it was.
 */
it('leaves the request shape to defaults, whatever the constructor assigns', function (): void {
    $schemas = ($this->component)(ProblemDetails::class, ($this->problem)(['type' => true, 'title' => true, 'status' => true, 'detail' => false, 'instance' => true, 'traceId' => false]), request: true);

    expect(array_values($schemas)[0]['required'])->toBe(['type', 'title', 'status', 'detail', 'traceId']);
});

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
