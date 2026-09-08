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

/*
 * The tie-break the docblock's third key is, stated from the contract rather than from the code:
 * `ExtensionOrder` is `TARGET_CLASS`, so two instances of one class carry ONE priority and neither
 * `before` nor `after` can name the other — the FQCN comparison is a class against itself. Nothing
 * intrinsic is left, so the order they were registered in is the answer, and the chains reading it are
 * first-match-wins or sequential. It is a real, published, author-controlled order, which is why
 * ResolvedExtensions::cacheSignature() has to carry it (ExtensionSignatureTest holds that half). Make
 * this sort arrival-free and this test is what says the key has become redundant.
 */
it('leaves two instances of one class in the order they were registered', function (): void {
    $first = new SorterDefaultA;
    $second = new SorterDefaultA;

    expect((new ExtensionSorter)->sort([$first, $second]))->toBe([$first, $second])
        ->and((new ExtensionSorter)->sort([$second, $first]))->toBe([$second, $first]);
});

it('declares its ordering per class, so two instances of one class can never differ in priority', function (): void {
    // The premise of the test above, and the reason "one class at two priorities" is not a case anything
    // can register: the ordering is declared on the CLASS, so both instances read one priority. An
    // instance-level priority would separate such a pair intrinsically and the position keyed by
    // ResolvedExtensions::cacheSignature() would be describing the wrong set.
    $declaration = (new ReflectionClass(ExtensionOrder::class))->getAttributes(Attribute::class);
    $targets = $declaration === [] ? Attribute::TARGET_ALL : ($declaration[0]->getArguments()[0] ?? Attribute::TARGET_ALL);

    expect($targets)->toBe(Attribute::TARGET_CLASS);
});

it('honours before edges over priority', function (): void {
    // SorterBeforeHigh is default priority but must precede the high-priority node.
    $sorted = sortedClasses([new SorterHighPriority, new SorterBeforeHigh]);

    expect($sorted)->toBe([SorterBeforeHigh::class, SorterHighPriority::class]);
});

it('throws on a cycle', function (): void {
    (new ExtensionSorter)->sort([new SorterCycleA, new SorterCycleB]);
})->throws(CyclicExtensionOrderException::class);
