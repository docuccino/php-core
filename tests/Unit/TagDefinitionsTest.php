<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;

/*
 * `tags.definitions` → the OAS 3.2 top-level `tags` array: which members are carried, the
 * weight-then-name ordering, and the parent pass that keeps the hierarchy a forest
 * (DocumentConfig::tagDefinitions() / ::tagParentIssues()).
 */

/**
 * @param  list<array<string, mixed>>  $definitions
 */
function tagConfig(array $definitions): DocumentConfig
{
    return new DocumentConfig('default', [], tags: ['definitions' => $definitions]);
}

it('carries every optional OAS 3.2 tag member from a definition', function (string $member, string $value): void {
    $tags = tagConfig([['name' => 'Billing', $member => $value]])->tagDefinitions();

    expect($tags)->toBe([['name' => 'Billing', $member => $value]]);
})->with([
    'summary' => ['summary', 'Billing'],
    'description' => ['description', 'Everything money.'],
    'kind' => ['kind', 'nav'],
]);

it('carries a parent that names a defined tag', function (): void {
    $tags = tagConfig([
        ['name' => 'Billing'],
        ['name' => 'Invoices', 'parent' => 'Billing'],
    ])->tagDefinitions();

    expect($tags[1])->toBe(['name' => 'Invoices', 'parent' => 'Billing']);
});

it('drops a non-string member value rather than emitting it', function (mixed $value): void {
    $tags = tagConfig([['name' => 'Billing', 'kind' => $value, 'parent' => $value]])->tagDefinitions();

    expect($tags)->toBe([['name' => 'Billing']])
        ->and(tagConfig([['name' => 'Billing', 'kind' => $value]])->tagParentIssues())->toBe([]);
})->with([
    'int' => [7],
    'array' => [['nav']],
    'null' => [null],
    'bool' => [true],
]);

it('skips an entry with no string name', function (): void {
    expect(tagConfig([['description' => 'orphan'], ['name' => 'Billing']])->tagDefinitions())
        ->toBe([['name' => 'Billing']]);
});

it('sorts by weight then name and does not emit the weight', function (): void {
    $tags = tagConfig([
        ['name' => 'Zebra', 'weight' => 10],
        ['name' => 'Alpha', 'weight' => 10],
        ['name' => 'Yak', 'weight' => 1],
    ])->tagDefinitions();

    expect(array_column($tags, 'name'))->toBe(['Yak', 'Alpha', 'Zebra'])
        ->and($tags[0])->not->toHaveKey('weight');
});

it('drops a parent naming an undefined tag and reports it', function (): void {
    $config = tagConfig([['name' => 'Invoices', 'parent' => 'Billing']]);

    expect($config->tagDefinitions())->toBe([['name' => 'Invoices']])
        ->and($config->tagParentIssues())->toBe([['tag' => 'Invoices', 'parent' => 'Billing', 'cycle' => false]]);
});

it('drops the link that closes a cycle and reports it as one', function (): void {
    // Sorted order is Billing, Invoices: Billing→Invoices is accepted first, so Invoices→Billing is
    // the link that closes the loop and the only one dropped.
    $config = tagConfig([
        ['name' => 'Invoices', 'parent' => 'Billing'],
        ['name' => 'Billing', 'parent' => 'Invoices'],
    ]);

    expect($config->tagDefinitions())->toBe([
        ['name' => 'Billing', 'parent' => 'Invoices'],
        ['name' => 'Invoices'],
    ])->and($config->tagParentIssues())->toBe([['tag' => 'Invoices', 'parent' => 'Billing', 'cycle' => true]]);
});

it('treats a tag parented to itself as a cycle', function (): void {
    $config = tagConfig([['name' => 'Billing', 'parent' => 'Billing']]);

    expect($config->tagDefinitions())->toBe([['name' => 'Billing']])
        ->and($config->tagParentIssues())->toBe([['tag' => 'Billing', 'parent' => 'Billing', 'cycle' => true]]);
});

it('breaks a longer cycle at one link and keeps the rest of the chain', function (): void {
    $config = tagConfig([
        ['name' => 'C', 'parent' => 'A'],
        ['name' => 'B', 'parent' => 'C'],
        ['name' => 'A', 'parent' => 'B'],
    ]);

    // A→B and B→C are accepted; C→A would close the loop.
    expect($config->tagDefinitions())->toBe([
        ['name' => 'A', 'parent' => 'B'],
        ['name' => 'B', 'parent' => 'C'],
        ['name' => 'C'],
    ])->and($config->tagParentIssues())->toBe([['tag' => 'C', 'parent' => 'A', 'cycle' => true]]);
});

it('keeps a deep valid chain and a shared parent intact', function (): void {
    $config = tagConfig([
        ['name' => 'Refunds', 'parent' => 'Invoices'],
        ['name' => 'Invoices', 'parent' => 'Billing'],
        ['name' => 'Credits', 'parent' => 'Billing'],
        ['name' => 'Billing'],
    ]);

    expect(array_column($config->tagDefinitions(), 'parent', 'name'))
        ->toBe(['Credits' => 'Billing', 'Invoices' => 'Billing', 'Refunds' => 'Invoices'])
        ->and($config->tagParentIssues())->toBe([]);
});

it('resolves to the same tags and issues whatever order the definitions are written in', function (): void {
    $definitions = [
        ['name' => 'Refunds', 'parent' => 'Invoices', 'kind' => 'nav', 'weight' => 2],
        ['name' => 'Billing', 'summary' => 'Billing', 'kind' => 'nav'],
        ['name' => 'Ghost', 'parent' => 'Nowhere'],
        ['name' => 'Invoices', 'parent' => 'Billing', 'weight' => 1],
    ];

    $expectedTags = tagConfig($definitions)->tagDefinitions();
    $expectedIssues = tagConfig($definitions)->tagParentIssues();

    foreach ([[3, 0, 1, 2], [1, 3, 0, 2], [2, 1, 3, 0]] as $order) {
        $shuffled = array_map(static fn (int $i): array => $definitions[$i], $order);

        expect(tagConfig($shuffled)->tagDefinitions())->toBe($expectedTags)
            ->and(tagConfig($shuffled)->tagParentIssues())->toBe($expectedIssues);
    }
});

it('emits no tags and no issues when definitions are absent or malformed', function (mixed $definitions): void {
    $config = new DocumentConfig('default', [], tags: ['definitions' => $definitions]);

    expect($config->tagDefinitions())->toBe([])
        ->and($config->tagParentIssues())->toBe([]);
})->with([
    'missing' => [null],
    'string' => ['Billing'],
    'empty' => [[]],
]);
