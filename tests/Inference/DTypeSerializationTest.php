<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Inference;

use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\CallableT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;

/**
 * @return list<DType>
 */
function representativeTypes(): array
{
    return [
        ScalarT::string(),
        ScalarT::int(),
        ScalarT::float(),
        ScalarT::bool(),
        new LiteralT('x'),
        new LiteralT(42),
        new LiteralT(true),
        new LiteralT(1.5),
        new NullT,
        new VoidT,
        new NeverT,
        new UnknownT('mixed'),
        new StatusMarkerT,
        new ListT(ScalarT::int()),
        new MapT(ScalarT::string(), ScalarT::int()),
        new ClassT('App\\Models\\User'),
        new ClassT('Illuminate\\Support\\Collection', [ScalarT::int(), new ClassT('App\\Models\\User')]),
        new EnumT('App\\Status', ['Active', 'Inactive']),
        new CallableT,
        new CallableT([ScalarT::int()], ScalarT::string()),
        new ArrayShapeT([
            new ArrayShapeField('id', ScalarT::int()),
            new ArrayShapeField('name', ScalarT::string(), optional: true),
        ]),
        UnionT::of([ScalarT::string(), new NullT]),
        IntersectionT::of([new ClassT('A'), new ClassT('B')]),
    ];
}

it('round-trips every DType through toArray/fromArray', function (DType $type): void {
    $restored = DType::fromArray($type->toArray());

    expect($restored->toArray())->toBe($type->toArray());
})->with(representativeTypes());

it('sorts union members canonically regardless of input order', function (): void {
    $a = UnionT::of([ScalarT::string(), ScalarT::int(), new NullT]);
    $b = UnionT::of([new NullT, ScalarT::int(), ScalarT::string()]);

    expect($a->toArray())->toBe($b->toArray());
});

it('places NullT last in a union (nullability convention)', function (): void {
    $union = UnionT::of([new NullT, ScalarT::string()]);

    expect($union)->toBeInstanceOf(UnionT::class);
    $members = $union->members;
    expect($members[count($members) - 1])->toBeInstanceOf(NullT::class);
});

it('flattens nested unions and dedupes members', function (): void {
    $union = UnionT::of([
        ScalarT::string(),
        UnionT::of([ScalarT::int(), ScalarT::string()]),
    ]);

    expect($union)->toBeInstanceOf(UnionT::class)
        ->and($union->members)->toHaveCount(2);
});

it('collapses a single-member union to that member', function (): void {
    $result = UnionT::of([ScalarT::int(), ScalarT::int()]);

    expect($result)->toBeInstanceOf(ScalarT::class);
});

it('distinguishes literal int from literal string in serialization', function (): void {
    expect((new LiteralT(1))->toArray())->not->toBe((new LiteralT('1'))->toArray())
        ->and(DType::fromArray((new LiteralT(1))->toArray()))->toEqual(new LiteralT(1))
        ->and(DType::fromArray((new LiteralT('1'))->toArray()))->toEqual(new LiteralT('1'));
});

it('preserves array-shape field order and optionality', function (): void {
    $shape = new ArrayShapeT([
        new ArrayShapeField('b', ScalarT::int()),
        new ArrayShapeField('a', ScalarT::string(), optional: true),
    ]);

    $restored = DType::fromArray($shape->toArray());
    expect($restored)->toBeInstanceOf(ArrayShapeT::class);
    /** @var ArrayShapeT $restored */
    expect($restored->fields[0]->key)->toBe('b')
        ->and($restored->fields[1]->key)->toBe('a')
        ->and($restored->fields[1]->optional)->toBeTrue();
});

it('gives A|B and B|A an identical canonical key', function (): void {
    $ab = UnionT::of([new ClassT('A'), new ClassT('B')]);
    $ba = UnionT::of([new ClassT('B'), new ClassT('A')]);

    expect($ab->canonicalKey())->toBe($ba->canonicalKey());
});
