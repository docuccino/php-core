<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Ordering\CyclicExtensionOrderException;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;

#[ExtensionOrder(priority: 100)]
final class SorterHighPriority {}

#[ExtensionOrder(priority: -100)]
final class SorterLowPriority {}

final class SorterDefaultA {}

final class SorterDefaultZ {}

#[ExtensionOrder(before: [SorterHighPriority::class])]
final class SorterBeforeHigh {}

#[ExtensionOrder(before: [SorterCycleB::class])]
final class SorterCycleA {}

#[ExtensionOrder(before: [SorterCycleA::class])]
final class SorterCycleB {}

/**
 * @param  list<object>  $extensions
 * @return list<string>
 */
function sortedClasses(array $extensions): array
{
    return array_map(
        static fn (object $e): string => $e::class,
        (new ExtensionSorter)->sort($extensions),
    );
}

it('orders by priority descending', function (): void {
    $sorted = sortedClasses([new SorterLowPriority, new SorterHighPriority, new SorterDefaultA]);

    expect($sorted)->toBe([SorterHighPriority::class, SorterDefaultA::class, SorterLowPriority::class]);
});

it('breaks priority ties by FQCN ascending, independent of input order', function (): void {
    $forward = sortedClasses([new SorterDefaultA, new SorterDefaultZ]);
    $reverse = sortedClasses([new SorterDefaultZ, new SorterDefaultA]);

    expect($forward)->toBe([SorterDefaultA::class, SorterDefaultZ::class])
        ->and($reverse)->toBe([SorterDefaultA::class, SorterDefaultZ::class]);
});

it('honours before edges over priority', function (): void {
    // SorterBeforeHigh is default priority but must precede the high-priority node.
    $sorted = sortedClasses([new SorterHighPriority, new SorterBeforeHigh]);

    expect($sorted)->toBe([SorterBeforeHigh::class, SorterHighPriority::class]);
});

it('throws on a cycle', function (): void {
    (new ExtensionSorter)->sort([new SorterCycleA, new SorterCycleB]);
})->throws(CyclicExtensionOrderException::class);
