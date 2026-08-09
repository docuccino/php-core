<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Inference;

use Docuccino\Core\Inference\ConstValue;

it('round-trips a scalar const value', function (): void {
    $value = ConstValue::scalar('status');

    expect(ConstValue::fromArray($value->toArray())->toArray())->toBe($value->toArray())
        ->and($value->render())->toBe("'status'");
});

it('folds a factory call descriptor with nested args', function (): void {
    $value = ConstValue::descriptor('AllowedFilter::exact', [ConstValue::scalar('status')]);

    expect($value->isDescriptor())->toBeTrue()
        ->and($value->render())->toBe("AllowedFilter::exact('status')")
        ->and(ConstValue::fromArray($value->toArray())->render())->toBe($value->render());
});

it('records the FQCN on a descriptor but shortens the class for display', function (): void {
    $value = ConstValue::descriptor(
        'Spatie\\QueryBuilder\\AllowedFilter::exact',
        [ConstValue::scalar('status')],
    );

    // The stored factory keeps the FQCN (and survives serialization)…
    expect($value->factory)->toBe('Spatie\\QueryBuilder\\AllowedFilter::exact')
        ->and($value->toArray()['factory'])->toBe('Spatie\\QueryBuilder\\AllowedFilter::exact')
        // …while render() shortens the class only for display.
        ->and($value->render())->toBe("AllowedFilter::exact('status')")
        ->and(ConstValue::fromArray($value->toArray())->render())->toBe($value->render());
});

it('carries chained calls on a descriptor, renders them, and round-trips', function (): void {
    // Rule::enum('…Status')->only(['Active'])->except(['Archived']) — the fluent chain survives the fold.
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar('App\\Enums\\Status')])
        ->withChainedCall('only', [ConstValue::array([ConstValue::scalar('Active')])])
        ->withChainedCall('except', [ConstValue::array([ConstValue::scalar('Archived')])]);

    expect($value->isDescriptor())->toBeTrue()
        ->and($value->chain)->toHaveCount(2)
        ->and($value->render())->toBe("Rule::enum('App\\Enums\\Status')->only(['Active'])->except(['Archived'])")
        ->and(ConstValue::fromArray($value->toArray())->render())->toBe($value->render())
        ->and(ConstValue::fromArray($value->toArray())->toArray())->toBe($value->toArray());
});

it('leaves an unchained descriptor serialization byte-identical (no chain key)', function (): void {
    $value = ConstValue::descriptor('AllowedFilter::exact', [ConstValue::scalar('status')]);

    expect($value->toArray())->not->toHaveKey('chain')
        ->and($value->chain)->toBe([]);
});

it('is a no-op to chain a call onto a non-descriptor', function (): void {
    expect(ConstValue::scalar('x')->withChainedCall('only', [])->isScalar())->toBeTrue();
});

it('round-trips an array of mixed const values', function (): void {
    $value = ConstValue::array([
        ConstValue::scalar('name'),
        ConstValue::descriptor('AllowedFilter::partial', [ConstValue::scalar('email')]),
    ]);

    expect($value->isArray())->toBeTrue()
        ->and($value->render())->toBe("['name', AllowedFilter::partial('email')]")
        ->and(ConstValue::fromArray($value->toArray())->toArray())->toBe($value->toArray());
});

it('renders scalar kinds distinctly', function (): void {
    expect(ConstValue::scalar(true)->render())->toBe('true')
        ->and(ConstValue::scalar(false)->render())->toBe('false')
        ->and(ConstValue::scalar(null)->render())->toBe('null')
        ->and(ConstValue::scalar(25)->render())->toBe('25');
});

it('carries a reason on an unknown value', function (): void {
    $value = ConstValue::unknown('non-constant');

    expect($value->render())->toBe('<unknown: non-constant>')
        ->and(ConstValue::fromArray($value->toArray())->render())->toBe($value->render());
});
