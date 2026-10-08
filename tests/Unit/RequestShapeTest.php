<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RouteDependencies;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\RequestShape;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\HiddenPropertyNode;
use Docuccino\Core\Tests\Fixtures\Tagged\Leaf;
use Docuccino\Core\Tests\Fixtures\Tagged\MisSealed;
use Docuccino\Core\Tests\Fixtures\Tagged\Node;
use Docuccino\Core\Tests\Fixtures\Tagged\Tree;
use Docuccino\Core\Tests\Fixtures\WidgetStock;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/*
 * `WidgetStock::$quantity` defaults to 1: a client may leave it out, and a response always writes it, so
 * the stock is two shapes — and so is any class that reaches it, however its type nests the stock.
 */
beforeEach(function (): void {
    $this->reached = static function (string $fqcn, array $classes, ?RouteDependencies $dependencies = null): bool {
        $classes[WidgetStock::class] ??= [new PropertyMetadata('quantity', ScalarT::int())];
        $metadata = [];
        foreach ($classes as $class => $properties) {
            $metadata[$class] = new ClassMetadata($class, $properties);
        }

        return RequestShape::reached($fqcn, new SchemaConverter(DefaultTypeMappers::all(), new StubTypeEngine(classes: $metadata), new ComponentRegistry, dependencies: $dependencies ?? new RouteDependencies, request: true));
    };
});

it('finds a request shape however the type nests it', function (DType $type, bool $reached): void {
    expect(($this->reached)(HiddenPropertyNode::class, [HiddenPropertyNode::class => [new PropertyMetadata('id', $type)]]))->toBe($reached);
})->with([
    'the class itself' => [new ClassT(WidgetStock::class), true],
    'a list of it' => [new ListT(new ClassT(WidgetStock::class)), true],
    'a map of it' => [new MapT(ScalarT::string(), new ClassT(WidgetStock::class)), true],
    'a shape holding it' => [new ArrayShapeT([new ArrayShapeField('stock', new ClassT(WidgetStock::class))]), true],
    'a nullable one' => [UnionT::of([new ClassT(WidgetStock::class), new NullT]), true],
    'an intersection' => [new IntersectionT([new ClassT(WidgetStock::class), new ClassT(Countable::class)]), true],
    'a generic argument' => [new ClassT(ArrayObject::class, [new ClassT(WidgetStock::class)]), true],
    'a scalar' => [ScalarT::int(), false],
    'a class of one shape' => [new ClassT(Leaf::class), false],
]);

it('weighs nothing a property it hides reaches', function (): void {
    expect(($this->reached)(HiddenPropertyNode::class, [HiddenPropertyNode::class => [new PropertyMetadata('internal_score', new ClassT(WidgetStock::class))]]))->toBeFalse();
});

it('weighs a seal by its members, through a member that holds the seal again', function (): void {
    $node = [new PropertyMetadata('kind', ScalarT::string()), new PropertyMetadata('child', new ClassT(Tree::class))];

    expect(($this->reached)(Tree::class, [Node::class => $node, Leaf::class => [new PropertyMetadata('value', ScalarT::int())]]))->toBeFalse()
        ->and(($this->reached)(Tree::class, [Node::class => $node, Leaf::class => [new PropertyMetadata('value', new ClassT(WidgetStock::class))]]))->toBeTrue()
        // The node reaches the leaf only through the seal it is a member of.
        ->and(($this->reached)(Node::class, [Node::class => $node, Leaf::class => [new PropertyMetadata('value', new ClassT(WidgetStock::class))]]))->toBeTrue();
});

it('weighs no member of a seal it cannot read, which publishes a bare object', function (): void {
    expect(($this->reached)(MisSealed::class, []))->toBeFalse();
});

it('records every class it weighed, since editing any of them can flip the answer', function (): void {
    $dependencies = new RouteDependencies;
    ($this->reached)(Tree::class, [Node::class => [new PropertyMetadata('child', new ClassT(Tree::class))], Leaf::class => []], $dependencies);

    expect($dependencies->files())->toContain(
        (string) (new ReflectionClass(Tree::class))->getFileName(),
        (string) (new ReflectionClass(Node::class))->getFileName(),
        (string) (new ReflectionClass(Leaf::class))->getFileName(),
    );
});
