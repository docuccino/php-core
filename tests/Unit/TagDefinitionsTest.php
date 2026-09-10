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

// One entry per name. OAS is explicit that "each tag name in the list MUST be unique", so two
// entries sharing a name make the document invalid however tidy they look: a renderer draws the
// section twice and a client generator mints the same type twice off the second one.

it('merges definitions that share a name into the one entry the tags array allows', function (): void {
    $config = tagConfig([
        ['name' => 'Billing', 'summary' => 'Billing'],
        ['name' => 'Billing', 'description' => 'Everything money.'],
    ]);

    // Silence is not a competing claim, so a member only one of them states is still carried.
    expect($config->tagDefinitions())->toBe([
        ['name' => 'Billing', 'summary' => 'Billing', 'description' => 'Everything money.'],
    ])->and($config->tagDuplicates())->toBe([['tag' => 'Billing', 'count' => 2, 'dropped' => []]]);
});

it('publishes neither reading of a member two definitions of one tag contradict', function (): void {
    $config = tagConfig([
        ['name' => 'Billing', 'summary' => 'Money in', 'kind' => 'nav'],
        ['name' => 'Billing', 'summary' => 'Money out', 'kind' => 'nav'],
        ['name' => 'Billing', 'description' => 'Everything money.'],
    ]);

    // The reader cannot see the config, so a summary the other definition contradicts would be a
    // confident lie; the members they agree on and the ones only one states survive.
    expect($config->tagDefinitions())->toBe([
        ['name' => 'Billing', 'description' => 'Everything money.', 'kind' => 'nav'],
    ])->and($config->tagDuplicates())->toBe([['tag' => 'Billing', 'count' => 3, 'dropped' => ['summary']]]);
});

it('reports a merge under the name that was duplicated, ordered by name', function (): void {
    $duplicates = tagConfig([
        ['name' => 'Webhooks'],
        ['name' => 'Billing', 'parent' => 'Webhooks'],
        ['name' => 'Webhooks'],
        ['name' => 'Billing', 'parent' => 'Nowhere'],
    ])->tagDuplicates();

    expect($duplicates)->toBe([
        ['tag' => 'Billing', 'count' => 2, 'dropped' => ['parent']],
        ['tag' => 'Webhooks', 'count' => 2, 'dropped' => []],
    ]);
});

it('positions a merged tag at the lowest weight its definitions state', function (): void {
    // A function of the definitions, not of which was written first: the same two entries either
    // way round put Billing ahead of the tag weighted 5.
    foreach ([[10, 1], [1, 10]] as [$first, $second]) {
        $tags = tagConfig([
            ['name' => 'Billing', 'weight' => $first],
            ['name' => 'Billing', 'weight' => $second],
            ['name' => 'Alerts', 'weight' => 5],
        ])->tagDefinitions();

        expect(array_column($tags, 'name'))->toBe(['Billing', 'Alerts']);
    }
});

it('merges before the parent pass, so a duplicate cannot fabricate a cycle', function (): void {
    // Two Billing entries, one of them parented to Invoices: merged first, the forest is a genuine
    // Billing <-> Invoices cycle and the report says so once.
    $config = tagConfig([
        ['name' => 'Billing'],
        ['name' => 'Billing', 'parent' => 'Invoices'],
        ['name' => 'Invoices', 'parent' => 'Billing'],
    ]);

    expect($config->tagParentIssues())->toBe([['tag' => 'Invoices', 'parent' => 'Billing', 'cycle' => true]])
        ->and(array_column($config->tagDefinitions(), 'name'))->toBe(['Billing', 'Invoices']);
});

it('projects one group per duplicated root rather than one per definition', function (): void {
    // x-tagGroups is built off the definitions, so a duplicate root used to draw the same sidebar
    // group twice.
    expect(tagConfig([
        ['name' => 'Billing'],
        ['name' => 'Billing'],
        ['name' => 'Invoices', 'parent' => 'Billing'],
    ])->tagGroups())->toBe([['name' => 'Billing', 'tags' => ['Billing', 'Invoices']]]);
});

it('merges to the same bytes however the duplicate definitions are ordered', function (): void {
    $definitions = [
        ['name' => 'Billing', 'summary' => 'Money in', 'weight' => 3],
        ['name' => 'Invoices', 'parent' => 'Billing'],
        ['name' => 'Billing', 'description' => 'Everything money.', 'weight' => 1],
        ['name' => 'Billing', 'summary' => 'Money out'],
    ];

    $expected = tagConfig($definitions)->tagDefinitions();
    expect($expected)->toBe([
        ['name' => 'Billing', 'description' => 'Everything money.'],
        ['name' => 'Invoices', 'parent' => 'Billing'],
    ]);

    foreach ([[3, 2, 1, 0], [1, 3, 0, 2], [2, 0, 3, 1]] as $order) {
        $shuffled = array_map(static fn (int $i): array => $definitions[$i], $order);

        expect(tagConfig($shuffled)->tagDefinitions())->toBe($expected)
            ->and(tagConfig($shuffled)->tagDuplicates())->toBe(tagConfig($definitions)->tagDuplicates());
    }
});

it('reports no duplicate when every definition names a different tag', function (): void {
    expect(tagConfig([['name' => 'Billing'], ['name' => 'Webhooks']])->tagDuplicates())->toBe([]);
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

// The same forest projected as `x-tagGroups` (DocumentConfig::tagGroups()) — the convention most
// renderers group a sidebar by, none of them reading the 3.2 `parent` member.
it('projects the tag forest as groups, roots first members of their own group', function (): void {
    $groups = tagConfig([
        ['name' => 'Billing'],
        ['name' => 'Invoices', 'parent' => 'Billing'],
        ['name' => 'Credit Notes', 'parent' => 'Invoices'],
        ['name' => 'Webhooks'],
    ])->tagGroups();

    // A deeper hierarchy flattens into its root's group; a childless root keeps a singleton group
    // rather than vanishing from a viewer that hides ungrouped tags.
    expect($groups)->toBe([
        ['name' => 'Billing', 'tags' => ['Billing', 'Invoices', 'Credit Notes']],
        ['name' => 'Webhooks', 'tags' => ['Webhooks']],
    ]);
});

it('projects no groups when no tag declares a parent', function (): void {
    expect(tagConfig([['name' => 'Billing'], ['name' => 'Webhooks']])->tagGroups())->toBe([]);
});

it('treats a tag whose parent link was dropped as a root of its own group', function (): void {
    $groups = tagConfig([
        ['name' => 'Billing', 'parent' => 'Billing'], // self-cycle: the link drops, the tag stays
        ['name' => 'Invoices', 'parent' => 'Billing'],
    ])->tagGroups();

    expect($groups)->toBe([
        ['name' => 'Billing', 'tags' => ['Billing', 'Invoices']],
    ]);
});
