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

/**
 * The key texts each generated kind claims: a spread of uuid versions including the nil one, and both
 * cases of each, since the patterns are case-insensitive. At least two apiece, or "these read as one
 * kind" has nothing to say.
 *
 * @return array<string, list<string>>
 */
function generatedKeyTexts(): array
{
    return [
        '<uuid>' => [
            'f47ac10b-58cc-1372-a567-0e02b2c3d479',
            '3fa85f64-5717-4562-b3fc-2c963f66afa6',
            '0193a1f0-0000-7000-8000-000000000000',
            '0193a1f0-ffff-7fff-bfff-ffffffffffff',
            '00000000-0000-0000-0000-000000000000',
            '3FA85F64-5717-4562-B3FC-2C963F66AFA6',
        ],
        '<ulid>' => [
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            '01BX5ZZKBKACTAV9WEVGEMMVRZ',
            '01arz3ndektsv4rrffq69g5fav',
        ],
    ];
}

/**
 * Keys someone chose, including the near misses: a uuid a digit short, a 26-character phrase, and two
 * hex strings of the lengths a real key comes in — an md5 digest and a mongo object id — since hex is
 * what the ulid pattern reads case-insensitively and length is the whole of what turns them away.
 *
 * @return list<string>
 */
function chosenKeyTexts(): array
{
    return [
        'core-details',
        'contact-details',
        'en-GB',
        '1',
        '3fa85f64-5717-4562-b3fc-2c963f66afa',
        'lorem-ipsum-dolor-sit-amet',
        '5d41402abc4b2a76b9719d911017c592',
        '507f1f77bcf86cd799439011',
    ];
}

/**
 * A map keyed by generated ids can never settle, because the keys move on every run while the structure
 * does not — and an API's open-map properties run into the dozens. So the KIND of a generated key is
 * what reaches the fingerprint; a key someone chose still reaches it as itself.
 */
it('reads every key of one generated kind alike, and nothing else as that kind', function (array $texts): void {
    $shapeOf = static fn (string $key): string => ResponseShape::of([$key => 1]);

    $foreign = array_values(array_diff(
        array_merge(chosenKeyTexts(), ...array_values(generatedKeyTexts())),
        $texts,
    ));

    expect(count($texts))->toBeGreaterThanOrEqual(2)
        ->and(array_unique(array_map($shapeOf, $texts)))->toHaveCount(1)
        ->and(array_intersect(array_map($shapeOf, $texts), array_map($shapeOf, $foreign)))->toBeEmpty();
})->with(array_map(static fn (array $texts): array => [$texts], generatedKeyTexts()));

it('reads a key someone chose as the key itself', function (): void {
    $chosen = chosenKeyTexts();
    $shapes = array_map(static fn (string $key): string => ResponseShape::of([$key => 1]), $chosen);

    expect(count($chosen))->toBeGreaterThanOrEqual(5)
        ->and(array_unique($shapes))->toHaveCount(count($chosen));
});

/**
 * The dataset above only proves the rows it lists, and the kind table is the thing that must not gain an
 * entry quietly: a `<ksuid>` added with no row would collapse a whole class of keys with the suite green.
 * So the rows are held to the source of truth in both directions.
 */
it('covers every generated key kind there is', function (): void {
    $listed = array_keys(generatedKeyTexts());
    $actual = ResponseShape::generatedKeyKinds();
    sort($listed);
    sort($actual);

    expect(count($actual))->toBeGreaterThanOrEqual(2)
        ->and($listed)->toBe($actual);
});

it('keeps how many ids a map holds while letting go of which', function (): void {
    $keyedBy = static fn (array $ids): array => ['values' => ['core-details' => array_fill_keys($ids, 's')]];

    $first = $keyedBy([
        '0193a1f0-0000-7000-8000-000000000001', '0193a1f0-0000-7000-8000-000000000002',
        '0193a1f0-0000-7000-8000-000000000003',
    ]);
    $second = $keyedBy([
        '3fa85f64-5717-4562-b3fc-2c963f66afa6', 'c0ffee00-dead-4bee-8000-000000000001',
        'f47ac10b-58cc-1372-a567-0e02b2c3d479',
    ]);
    $grown = $keyedBy([
        '3fa85f64-5717-4562-b3fc-2c963f66afa6', 'c0ffee00-dead-4bee-8000-000000000001',
        'f47ac10b-58cc-1372-a567-0e02b2c3d479', '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
    ]);

    expect(ResponseShape::of($first))->toBe(ResponseShape::of($second))
        ->and(ResponseShape::of($first))->not->toBe(ResponseShape::of($grown))
        ->and(ResponseShape::of($first))->not->toBe(ResponseShape::of(['values' => ['core-details' => (object) []]]));
});

it('collapses generated keys at any depth, and only the keys that are generated', function (): void {
    $deep = static fn (string $id): array => ['data' => [['attributes' => ['translations' => [$id => ['title' => 's']]]]]];

    expect(ResponseShape::of($deep('0193a1f0-0000-7000-8000-000000000001')))
        ->toBe(ResponseShape::of($deep('c0ffee00-dead-4bee-8000-000000000001')))
        ->and(ResponseShape::of($deep('0193a1f0-0000-7000-8000-000000000001')))
        ->not->toBe(ResponseShape::of($deep('en-GB')));
});

it('reads the keys of an object the same way it reads the keys of a map', function (): void {
    $first = (object) ['values' => (object) ['core-details' => (object) ['3fa85f64-5717-4562-b3fc-2c963f66afa6' => 's']]];
    $second = (object) ['values' => (object) ['core-details' => (object) ['c0ffee00-dead-4bee-8000-000000000001' => 's']]];
    $renamed = (object) ['values' => (object) ['other-details' => (object) ['3fa85f64-5717-4562-b3fc-2c963f66afa6' => 's']]];

    expect(ResponseShape::of($first))->toBe(ResponseShape::of($second))
        ->and(ResponseShape::of($first))->not->toBe(ResponseShape::of($renamed));
});

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
    'each generated key is still a place of its own' => [
        ['values' => ['core-details' => [
            '3fa85f64-5717-4562-b3fc-2c963f66afa6' => 's',
            'c0ffee00-dead-4bee-8000-000000000001' => 's',
        ]]],
        2,
    ],
]);

it('stops rather than recursing forever into a very deep body', function (): void {
    $deep = 'leaf';
    for ($i = 0; $i < 200; $i++) {
        $deep = ['next' => $deep];
    }

    expect(ResponseShape::of($deep))->toBeString()
        ->and(ResponseShape::populatedPaths($deep))->toBe(0);
});
