<?php

declare(strict_types=1);

use Docuccino\Core\Examples\ResponseShape;

/**
 * The shape fingerprint is what decides whether a re-recording rewrites a committed file, so the
 * property that matters is exactly this: it moves when the structure moves and never when only the
 * values do.
 */
it('is blind to the values a body carries', function (mixed $first, mixed $second): void {
    expect(ResponseShape::of($first))->toBe(ResponseShape::of($second));
})->with([
    'a timestamp' => [['created_at' => '2026-01-01T00:00:00Z'], ['created_at' => '2019-07-04T11:32:08Z']],
    'an autoincrement key' => [['id' => 1], ['id' => 90210]],
    'a uuid' => [['id' => '0193a1f0-0000-7000-8000-000000000000'], ['id' => 'c0ffee00-dead-4bee-8000-000000000001']],
    'a longer list of the same rows' => [
        [['id' => 1, 'name' => 'a']],
        [['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']],
    ],
    'members in another order' => [['a' => 1, 'b' => 's'], ['b' => 't', 'a' => 2]],
    'list members in another order' => [[1, 'a'], ['b', 2]],
]);

it('moves when the structure moves', function (mixed $first, mixed $second): void {
    expect(ResponseShape::of($first))->not->toBe(ResponseShape::of($second));
})->with([
    'a member appears' => [['id' => 1], ['id' => 1, 'name' => 'a']],
    'a member is renamed' => [['id' => 1], ['key' => 1]],
    'a type changes' => [['total' => 1], ['total' => '1']],
    'a member goes null' => [['deleted_at' => null], ['deleted_at' => '2026-01-01']],
    'an int becomes a float' => [['total' => 1], ['total' => 1.5]],
    'a list nests' => [[1], [[1]]],
    'an empty object is not an empty list' => [(object) [], []],
    'a bool is not a string' => [['ok' => true], ['ok' => 'true']],
]);

it('counts the places a body actually fills in', function (mixed $body, int $expected): void {
    expect(ResponseShape::populatedPaths($body))->toBe($expected);
})->with([
    'a scalar' => ['x', 1],
    'null fills nothing' => [null, 0],
    'a flat object' => [['a' => 1, 'b' => 2], 2],
    'a null member is not filled in' => [['a' => 1, 'b' => null], 1],
    'nested members each count' => [['a' => ['b' => 1, 'c' => 2]], 2],
    'a hundred rows count as one row' => [array_fill(0, 100, ['id' => 1, 'name' => 'a']), 2],
    'an empty object fills nothing' => [(object) [], 0],
]);

it('stops rather than recursing forever into a very deep body', function (): void {
    $deep = 'leaf';
    for ($i = 0; $i < 200; $i++) {
        $deep = ['next' => $deep];
    }

    expect(ResponseShape::of($deep))->toBeString()
        ->and(ResponseShape::populatedPaths($deep))->toBe(0);
});
