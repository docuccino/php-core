<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;

/**
 * The invariant two sites owe each other: TWO NODES THE REGISTRY REFUSES TO MERGE NEVER SHARE AN ID.
 * `ComponentRegistry::mergesInto()` decides whether two registrations are one component; the mint
 * decides what id each publishes. Neither can be checked against the other by asking either of them
 * what its rule is — a guard written that way agrees with whatever the code does — so everything here
 * is stated off the DOCUMENT: an entry of `components.schemas` is a published node, and an id two
 * entries carry addresses neither of them.
 *
 * The axes are what a component can differ in and still be published: the shape it states, the prose
 * around the shape, the order it lists `required` in, the name its registrar asked for, and the class
 * it was recovered from. Every one of them is a byte a consumer reads, so every one of them is two
 * components — and two ids.
 */
it('publishes one id per component, whatever the two of them differ in', function (array $registrations, int $components): void {
    /** @var list<array{string, array<string, mixed>, string|null}> $registrations */
    $registry = new ComponentRegistry;
    foreach ($registrations as [$name, $schema, $schemaId]) {
        $registry->registerSchema($name, $schema, $schemaId);
    }

    $ids = componentSchemaIds(['components' => ['schemas' => assembledComponentSchemas($registry)]]);

    // Both halves matter. Without the first the case proves nothing — one component trivially carries
    // one id — and the registry keeping the pair apart is exactly the premise the second half is about.
    expect($ids)->toHaveCount($components)
        ->and(array_unique(array_values($ids)))->toHaveCount($components)
        ->and(array_values($ids))->each->toStartWith('sch:v1:');
})->with([
    // A shape difference: the one axis the old mint did read.
    'a differing shape' => [[
        ['Envelope', ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]], null],
        ['Envelope', ['type' => 'object', 'properties' => ['total' => ['type' => 'string']]], null],
    ], 2],
    // The three annotation members: prose a consumer reads, and a contract that lies about which
    // component it belongs to is worse than no prose at all.
    'a differing description' => [[
        ['Envelope', ['type' => 'object', 'description' => 'The list envelope.'], null],
        ['Envelope', ['type' => 'object', 'description' => 'The cursor envelope.'], null],
    ], 2],
    'a differing title' => [[
        ['Envelope', ['type' => 'object', 'title' => 'Page'], null],
        ['Envelope', ['type' => 'object', 'title' => 'Cursor page'], null],
    ], 2],
    'a differing example' => [[
        ['Envelope', ['type' => 'object', 'example' => ['total' => 1]], null],
        ['Envelope', ['type' => 'object', 'example' => ['total' => 2]], null],
    ], 2],
    // `required` order is a byte of the published document, and a generated client reads the list in
    // the order the document states it.
    'a differing required order' => [[
        ['Envelope', ['type' => 'object', 'required' => ['a', 'b']], null],
        ['Envelope', ['type' => 'object', 'required' => ['b', 'a']], null],
    ], 2],
    // Equal bytes under two asks: two names a client writes against, so two nodes.
    'one shape under two asks' => [[
        ['PaginationLinks', ['type' => 'object', 'properties' => ['next' => ['type' => 'string']]], null],
        ['SimplePaginationLinks', ['type' => 'object', 'properties' => ['next' => ['type' => 'string']]], null],
    ], 2],
    // Equal bytes from two classes: the registry publishes both, because an edit to one must not read
    // as an edit to the other.
    'one shape from two classes' => [[
        ['Widget', ['type' => 'object'], 'App\\Data\\Widget'],
        ['Widget', ['type' => 'object'], 'App\\Legacy\\Widget'],
    ], 2],
    // The other direction: what the registry DOES merge is one component with one id, so the guard
    // above cannot be satisfied by minting a fresh id per registration.
    'one class registered twice' => [[
        ['Widget', ['type' => 'object'], 'App\\Data\\Widget'],
        ['Widget', ['type' => 'object'], 'App\\Data\\Widget'],
    ], 1],
    'one id-less shape registered twice' => [[
        ['Envelope', ['type' => 'object', 'description' => 'The list envelope.'], null],
        ['Envelope', ['type' => 'object', 'description' => 'The list envelope.'], null],
    ], 1],
]);

it('addresses both components when two of them differ only in their prose', function (): void {
    // The reported symptom: the pair the old mint collapsed. `identities()` maps an id to the ONE node
    // it found it on, so a shared id leaves the other component unaddressable and answers provenance
    // questions about the wrong one.
    $registry = new ComponentRegistry;
    $shape = ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]];
    $registry->registerSchema('Envelope', $shape + ['description' => 'The list envelope.']);
    $registry->registerSchema('Envelope', $shape + ['description' => 'The cursor envelope.']);

    $schemas = assembledComponentSchemas($registry);
    $ids = componentSchemaIds(['components' => ['schemas' => $schemas]]);
    $index = ContractIndex::fromArray(['components' => ['schemas' => $schemas]]);

    expect($schemas)->toHaveCount(2);

    foreach ($ids as $name => $id) {
        expect($index->identities())->toHaveKey($id)
            ->and($index->identities()[$id])->toBe(['components', 'schemas', $name]);
    }
});

it('leaves every id where it was when an unrelated component arrives, or arrives first', function (): void {
    // Locality: an id is a function of the component and of nothing beside it. A component that shares
    // no ask with the newcomer is untouched by it, name and id both — and the newcomer registers
    // BEFORE the two it joins, because a route added to an application registers wherever it falls.
    $envelope = ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]];
    $merchant = ['type' => 'object', 'properties' => ['vat' => ['type' => 'string']]];

    $before = new ComponentRegistry;
    $before->registerSchema('Envelope', $envelope);
    $before->registerSchema('Widget', ['type' => 'object'], 'App\\Data\\Widget');

    $after = new ComponentRegistry;
    $after->registerSchema('Merchant', $merchant);
    $after->registerSchema('Envelope', $envelope);
    $after->registerSchema('Widget', ['type' => 'object'], 'App\\Data\\Widget');

    $reordered = new ComponentRegistry;
    $reordered->registerSchema('Widget', ['type' => 'object'], 'App\\Data\\Widget');
    $reordered->registerSchema('Envelope', $envelope);

    $was = componentSchemaIds(['components' => ['schemas' => assembledComponentSchemas($before)]]);
    $now = componentSchemaIds(['components' => ['schemas' => assembledComponentSchemas($after)]]);
    $swapped = componentSchemaIds(['components' => ['schemas' => assembledComponentSchemas($reordered)]]);

    expect($was)->toHaveCount(2)
        ->and($now)->toHaveCount(3)
        ->and(array_intersect_key($now, $was))->toBe($was)
        ->and($swapped)->toBe($was);
});

it('keeps a component its id when a sibling pushes it off the name it asked for', function (): void {
    // A component moved off its name by a claim that arrived beside it has been RENAMED, and the differ
    // pairs it by the id it kept. Minting from the PUBLISHED name would satisfy the uniqueness guard
    // above and lose that: the rename would read as one component removed and another added.
    $alone = new ComponentRegistry;
    $alone->registerSchema('Envelope', ['type' => 'object', 'description' => 'The list envelope.']);

    $contested = new ComponentRegistry;
    $contested->registerSchema('Envelope', ['type' => 'object', 'description' => 'The list envelope.']);
    $contested->registerSchema('Envelope', ['type' => 'object', 'description' => 'The cursor envelope.']);

    $before = componentSchemaIds(['components' => ['schemas' => assembledComponentSchemas($alone)]]);
    $after = componentSchemaIds(['components' => ['schemas' => assembledComponentSchemas($contested)]]);

    expect($before)->toHaveCount(1)
        ->and($after)->toHaveCount(2)
        // The name moved — that is what a contest does — and the id did not.
        ->and(array_keys($after))->not->toBe(array_keys($before))
        ->and(array_values($after))->toContain(...array_values($before));
});
