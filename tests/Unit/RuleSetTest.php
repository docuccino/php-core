<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\TaggedVariants;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/*
 * A rule set whose tagged objects had their presence rules moved onto the partition. Every rewrite has to
 * carry the merged reading beside the fields, or an object whose partition is later given up publishes its
 * members with those rules missing — a required tag optional, and nothing saying when a member is needed.
 */

it('refuses variants without the merged reading of every field', function (array $merged): void {
    expect(static fn (): RuleSet => new RuleSet(['t' => [], 'x' => []], [taggedSetVariants()], $merged))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'none at all' => [[]],
    'a field short' => [['t' => []]],
    'a field the set does not have' => [['t' => [], 'x' => [], 'y' => []]],
]);

it('rewrites the merged reading with the fields', function (): void {
    $set = taggedSet()->mapFields(static fn (array $rules): array => [...$rules, ValidationRule::of('bail')]);

    expect(array_map(taggedSetNames(...), $set->fields))->toBe(['t' => 'in|bail', 'x' => 'string|bail', 'o' => 'array|bail', 'o.k' => 'in|bail', 'o.y' => 'bail', 'note' => 'string|bail'])
        ->and(array_map(taggedSetNames(...), $set->merged))->toBe(['t' => 'required|in|bail', 'x' => 'required_if|string|bail', 'o' => 'array|bail', 'o.k' => 'required|in|bail', 'o.y' => 'required_if|bail', 'note' => 'string|bail'])
        ->and($set->variants)->toHaveCount(2);
});

it('gives up exactly the variants a key is read off, and reads their members as merged again', function (array $keys, array $kept, array $fields): void {
    $set = taggedSet()->releasing($keys);

    expect(array_map(static fn (TaggedVariants $variant): string => $variant->path, $set->variants))->toBe($kept)
        ->and(array_map(taggedSetNames(...), $set->fields))->toBe($fields);
})->with([
    'the tag' => [['t'], ['o'], ['t' => 'required|in', 'x' => 'required_if|string', 'o' => 'array', 'o.k' => 'in', 'o.y' => '', 'note' => 'string']],
    'a gated member' => [['x'], ['o'], ['t' => 'required|in', 'x' => 'required_if|string', 'o' => 'array', 'o.k' => 'in', 'o.y' => '', 'note' => 'string']],
    // A member on every branch is no part of the proof, and neither is the object's own key.
    'a member every branch keeps' => [['note', 'o'], ['', 'o'], ['t' => 'in', 'x' => 'string', 'o' => 'array', 'o.k' => 'in', 'o.y' => '', 'note' => 'string']],
    'a nested tag' => [['o.k'], [''], ['t' => 'in', 'x' => 'string', 'o' => 'array', 'o.k' => 'required|in', 'o.y' => 'required_if', 'note' => 'string']],
    'both' => [['t', 'o.y'], [], ['t' => 'required|in', 'x' => 'required_if|string', 'o' => 'array', 'o.k' => 'required|in', 'o.y' => 'required_if', 'note' => 'string']],
]);

it('drops keys from the fields and the merged reading alike, and forgets the merged reading with the last variant', function (): void {
    $kept = taggedSet()->without(['note']);
    $bare = taggedSet()->without(['t', 'o.k']);

    expect(array_keys($kept->fields))->toBe(['t', 'x', 'o', 'o.k', 'o.y'])
        ->and(array_keys($kept->merged))->toBe(['t', 'x', 'o', 'o.k', 'o.y'])
        ->and($kept->variants)->toHaveCount(2)
        ->and(array_map(taggedSetNames(...), $bare->fields))->toBe(['x' => 'required_if|string', 'o' => 'array', 'o.y' => 'required_if', 'note' => 'string'])
        ->and($bare->variants)->toBe([])
        ->and($bare->merged)->toBe([])
        ->and($bare->merged())->toBe($bare);
});

it('reads as the merged fields with every variant given up', function (): void {
    $merged = taggedSet()->merged();

    expect($merged->fields)->toEqual(taggedSet()->merged)
        ->and($merged->variants)->toBe([]);
});
