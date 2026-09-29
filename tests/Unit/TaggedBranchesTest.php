<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\DiscriminatedUnion;
use Docuccino\Core\Extensions\Validation\TaggedBranches;
use Docuccino\Core\Extensions\Validation\TaggedVariants;

/*
 * A tagged object in a finished request body, published as the union of one component per tag value. What
 * the rules must say for a partition is the adapter's to prove; this is the half that writes it — onto the
 * schema the whole build left, or not at all where that schema is no longer the one the rules described.
 */

it('publishes each tag value as a component holding the members that value keeps', function (): void {
    $components = new ComponentRegistry;
    [$body] = TaggedBranches::apply(taggedBody(taggedObject()), taggedBody(taggedObject()), [paymentVariants()], $components, 'StoreOrderRequest', 'App\\StoreOrderRequest#request');

    expect($body['properties']['payment'])->toBe([
        'anyOf' => [
            ['$ref' => '#/components/schemas/StoreOrderRequestPaymentCard'],
            ['$ref' => '#/components/schemas/StoreOrderRequestPaymentTransfer'],
        ],
        'description' => 'How the order is paid.',
        'minProperties' => 1,
    ])
        ->and($components->schemas()['StoreOrderRequestPaymentCard'])->toBe([
            'type' => 'object',
            'properties' => [
                'method' => ['type' => 'string', 'description' => 'Which way.', 'const' => 'card'],
                'number' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
            // The tag, then what the merged object required, then what this value requires.
            'required' => ['method', 'note', 'number'],
        ])
        ->and($components->schemas()['StoreOrderRequestPaymentTransfer']['properties'])->not->toHaveKey('number')
        // Identified by the body, the tag and the value, so no other part of the application can move it.
        ->and(array_values($components->schemaIds()))->toBe([
            'App\\StoreOrderRequest#request/payment.method=card',
            'App\\StoreOrderRequest#request/payment.method=transfer',
        ]);
});

it('keeps null and the empty object beside the branches where the object admits them', function (array $object, bool $admitsEmpty, array $outside): void {
    $variants = paymentVariants($admitsEmpty);
    [$body] = TaggedBranches::apply(taggedBody($object), taggedBody($object), [$variants], new ComponentRegistry, 'StoreOrderRequest', 'App\\StoreOrderRequest#request');

    expect(array_slice($body['properties']['payment']['anyOf'], 2))->toBe($outside);
})->with([
    'neither' => [taggedObject(), false, []],
    'null, as a type list' => [['type' => ['object', 'null']] + taggedObject(), false, [['type' => 'null']]],
    'null, as the anyOf the other nullable policy writes' => [['anyOf' => [['type' => 'object'], ['type' => 'null']]] + array_diff_key(taggedObject(), ['type' => true]), false, [['type' => 'null']]],
    'the empty object' => [taggedObject(), true, [DiscriminatedUnion::EMPTY_OBJECT]],
    'both' => [['type' => ['object', 'null']] + taggedObject(), true, [DiscriminatedUnion::EMPTY_OBJECT, ['type' => 'null']]],
]);

it('keeps the enum a tag is typed by, narrowed to the one value', function (): void {
    $object = taggedObject();
    $object['properties']['method'] = ['$ref' => '#/components/schemas/PaymentMethod', 'example' => 'card'];
    $components = new ComponentRegistry;

    TaggedBranches::apply(taggedBody($object), taggedBody($object), [paymentVariants()], $components, 'StoreOrderRequest', 'App\\StoreOrderRequest#request');

    expect($components->schemas()['StoreOrderRequestPaymentCard']['properties']['method'])->toBe(['$ref' => '#/components/schemas/PaymentMethod', 'const' => 'card']);
});

it('puts an object back as the merged reading has it where its schema is no longer the one the rules left', function (array $object): void {
    $components = new ComponentRegistry;
    // The merged reading, marked so it cannot be mistaken for the stripped object it stands in for.
    $merged = taggedBody(['description' => 'Required when the order is paid.'] + $object);

    // Nothing split, nothing published, and no component registered for a branch that is not there.
    expect(TaggedBranches::apply(taggedBody($object), $merged, [paymentVariants()], $components, 'StoreOrderRequest', 'App\\StoreOrderRequest#request'))->toBe([$merged, []])
        ->and($components->schemas())->toBe([]);
})->with([
    'a keyword constraining its members' => [['additionalProperties' => false] + taggedObject()],
    'a composition written on it' => [['allOf' => [['type' => 'object']]] + taggedObject()],
    'an anyOf that is not the nullable one' => [['anyOf' => [['type' => 'object'], ['type' => 'string']]] + array_diff_key(taggedObject(), ['type' => true])],
    'a type besides object and null' => [['type' => ['object', 'string']] + taggedObject()],
    'a declaration that replaced it' => [['$ref' => '#/components/schemas/Payment']],
    'no tag among its members' => [['properties' => ['number' => ['type' => 'string']]] + taggedObject()],
]);

it('puts back the deepest node both bodies have where the path names nothing in it', function (string $path, bool $whole): void {
    $body = taggedBody(taggedObject());
    $merged = ['description' => 'Merged body.'] + taggedBody(['description' => 'Merged.'] + taggedObject());
    $variants = new TaggedVariants($path, 'method', ['number'], paymentVariants()->branches);

    expect(TaggedBranches::apply($body, $merged, [$variants], new ComponentRegistry, 'StoreOrderRequest', 'App\\StoreOrderRequest#request'))
        ->toBe([$whole ? $merged : taggedBody(['description' => 'Merged.'] + taggedObject()), []]);
})->with([
    'nothing at the first segment' => ['missing', true],
    'nothing under the object' => ['payment.missing', false],
    'no items under the object' => ['payment.*', false],
    'nothing named at the root' => ['note.*', true],
]);

it('gives up every variant inside an object it puts back, and registers none of their branches', function (): void {
    $components = new ComponentRegistry;
    $outer = static fn (array $payment): array => [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => ['kind' => ['type' => 'string'], 'payment' => $payment],
        'required' => ['kind'],
    ];
    $merged = ['description' => 'Merged.'] + $outer(['description' => 'Merged too.'] + taggedObject());
    $kind = new TaggedVariants('', 'kind', [], [
        ['value' => 'retail', 'name' => 'Retail', 'members' => [], 'required' => []],
        ['value' => 'trade', 'name' => 'Trade', 'members' => [], 'required' => []],
    ]);

    // The inner object could be split on its own, but it lies inside one that cannot.
    expect(TaggedBranches::apply($outer(taggedObject()), $merged, [paymentVariants(), $kind], $components, 'StoreOrderRequest', 'App\\StoreOrderRequest#request'))->toBe([$merged, []])
        ->and($components->schemas())->toBe([]);
});

it('keeps a variant beside one it gives up, and says which it published', function (): void {
    $components = new ComponentRegistry;
    $body = ['type' => 'object', 'properties' => ['payment' => taggedObject(), 'refund' => ['additionalProperties' => false] + taggedObject()]];
    $merged = ['type' => 'object', 'properties' => ['payment' => ['description' => 'Merged.'] + taggedObject(), 'refund' => ['description' => 'Merged.'] + taggedObject()]];
    $refund = new TaggedVariants('refund', 'method', ['number'], paymentVariants()->branches);

    [$split, $published] = TaggedBranches::apply($body, $merged, [paymentVariants(), $refund], $components, 'StoreOrderRequest', 'App\\StoreOrderRequest#request');

    expect(array_map(static fn (TaggedVariants $variant): string => $variant->path, $published))->toBe(['payment'])
        ->and($split['properties']['payment'])->toHaveKey('anyOf')
        ->and($split['properties']['refund'])->toBe($merged['properties']['refund']);
});

it('reaches an object under a list item and under a map value', function (string $slot): void {
    $body = ['type' => 'object', 'properties' => ['payments' => ['type' => $slot === 'items' ? 'array' : 'object', $slot => taggedObject()]]];
    $variants = new TaggedVariants('payments.*', 'method', ['number'], paymentVariants()->branches);

    [$split] = TaggedBranches::apply($body, $body, [$variants], new ComponentRegistry, 'StoreOrderRequest', 'App\\StoreOrderRequest#request');

    expect($split['properties']['payments'][$slot]['anyOf'][0])->toBe(['$ref' => '#/components/schemas/StoreOrderRequestPaymentCard']);
})->with(['items', 'additionalProperties']);

it('splits the inner object first, so the outer branches carry its union', function (): void {
    $components = new ComponentRegistry;
    $body = [
        'type' => 'object',
        'properties' => ['kind' => ['type' => 'string'], 'payment' => taggedObject()],
        'required' => ['kind'],
    ];
    $outer = new TaggedVariants('', 'kind', [], [
        ['value' => 'retail', 'name' => 'Retail', 'members' => [], 'required' => []],
        ['value' => 'trade', 'name' => 'Trade', 'members' => [], 'required' => []],
    ]);

    // Handed over outer-first, as a rule set may list them.
    TaggedBranches::apply($body, $body, [$outer, paymentVariants()], $components, 'StoreOrderRequest', 'App\\StoreOrderRequest#request');

    expect($components->schemas()['StoreOrderRequestRetail']['properties']['payment']['anyOf'][0])
        ->toBe(['$ref' => '#/components/schemas/StoreOrderRequestPaymentCard']);
});
