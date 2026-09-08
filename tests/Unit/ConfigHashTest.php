<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;

/**
 * The rule {@see DocumentConfig::hash()} owes, stated here rather than read back off the method: the
 * hashed shape is the raw config bag MINUS `export` and `viewer`, and nothing else. Both are excluded
 * because neither shapes an emitted byte — `export` says where artifacts land, `viewer` is boot-time
 * wiring for the runtime endpoints — and the hash is both a published value (`document.configHash`)
 * and a fragment-cache key input, so getting the set wrong either churns bytes over nothing or hands
 * a build fragments assembled under different shaping config.
 *
 * Stated in two directions on purpose. An excluded key must make the hash AGREE, and every other key
 * must still make it DIFFER — a hash() that folded in nothing at all satisfies the first half and is
 * useless.
 */

/**
 * A document bag shaped like a real one: every top-level key the shipped config carries, and every
 * `viewer` key the reference documents.
 *
 * @return array<string, mixed>
 */
function configHashBag(): array
{
    return [
        'info' => ['title' => 'API Documentation', 'version' => '1.0.0'],
        'servers' => [['url' => 'https://api.example.com']],
        'routes' => ['include' => ['api/*'], 'exclude' => []],
        'security' => ['schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]],
        'error_responses' => 'default',
        'tags' => ['default_strategy' => 'controller'],
        'content' => ['dir' => 'resources/docs/api'],
        'overlays' => ['docs/overlays/*.json'],
        'representation' => ['nullability' => 'union'],
        'versioning' => 'semver',
        'integrations' => ['sanctum' => ['enabled' => true]],
        'export' => ['path' => 'docs/openapi.json'],
        'viewer' => configHashViewerBag(),
    ];
}

/**
 * @return array<string, mixed>
 */
function configHashViewerBag(): array
{
    return [
        'route' => '/docs/api',
        'gate' => null,
        'middleware' => ['web', 'throttle:60,1'],
        'source' => 'generate',
        'driver' => 'scalar',
        'cdn' => false,
        'configuration' => ['theme' => 'purple'],
    ];
}

/**
 * @param  array<string, mixed>  $raw
 */
function configHashOf(array $raw): string
{
    return (new DocumentConfig(key: 'default', info: [], raw: $raw))->hash();
}

it('hashes the config bag minus export and viewer, and nothing else', function (): void {
    // Total over the bag's own keys: dropping one changes the hash if and only if it is excluded. So
    // no key falls in the gap between "proved excluded" and "proved hashed" — the union is asserted
    // against the domain — and a hash() folding in nothing fails on the first hashed key.
    $excluded = ['export', 'viewer'];

    $bag = configHashBag();
    $base = configHashOf($bag);

    $ignored = [];
    $hashed = [];

    foreach (array_keys($bag) as $key) {
        $without = $bag;
        unset($without[$key]);

        if (configHashOf($without) === $base) {
            $ignored[] = $key;
        } else {
            $hashed[] = $key;
        }
    }

    expect($ignored)->toBe($excluded)
        ->and($hashed)->toBe(array_values(array_diff(array_keys($bag), $excluded)))
        // …and the bag really did carry both excluded keys, so neither list is short by accident.
        ->and(array_keys($bag))->toContain(...$excluded);
});

it('hashes two documents alike when only their whole viewer bag differs', function (): void {
    // Wholesale, not key by key: a viewer key nobody has invented yet has to be out of the hash too,
    // so the guard is over the bag rather than over the keys the reference happens to document.
    $bag = configHashBag();

    $rewired = $bag;
    $rewired['viewer'] = [
        'route' => '/internal/reference',
        'gate' => 'view-api-docs',
        'middleware' => ['api'],
        'source' => 'cache',
        'driver' => 'redoc',
        'cdn' => true,
        'configuration' => ['layout' => 'classic'],
        'something-nobody-has-written-yet' => ['deeply' => ['nested' => true]],
    ];

    $unwired = $bag;
    unset($unwired['viewer']);

    expect(configHashOf($rewired))->toBe(configHashOf($bag))
        ->and(configHashOf($unwired))->toBe(configHashOf($bag));
});

it('changes not a byte of the hash for each documented viewer key on its own', function (string $key, mixed $value): void {
    $viewer = configHashViewerBag();
    $viewer[$key] = $value;

    $bag = configHashBag();
    $changed = $bag;
    $changed['viewer'] = $viewer;

    expect(configHashOf($changed))->toBe(configHashOf($bag))
        // The key was one the bag already carried, so the row moved a value rather than adding one.
        ->and(configHashViewerBag())->toHaveKey($key);
})->with([
    'route' => ['route', '/somewhere/else'],
    'middleware' => ['middleware', ['api', 'auth']],
    'gate' => ['gate', 'view-api-docs'],
    'source' => ['source', 'artifact'],
    'driver' => ['driver', 'redoc'],
    'cdn' => ['cdn', true],
    'configuration' => ['configuration', ['layout' => 'classic']],
]);

it('moves the hash for each key that DOES shape the document', function (string $key, mixed $value): void {
    // The other direction, one key at a time. `viewer` sits beside keys that look just as much like
    // wiring and are not: a content directory or an overlay glob changes what the document says.
    $bag = configHashBag();
    $changed = $bag;
    $changed[$key] = $value;

    expect(configHashOf($changed))->not->toBe(configHashOf($bag));
})->with([
    'info' => ['info', ['title' => 'Other', 'version' => '2.0.0']],
    'servers' => ['servers', [['url' => 'https://staging.example.com']]],
    'routes' => ['routes', ['include' => ['api/v2/*'], 'exclude' => []]],
    'security' => ['security', ['schemes' => []]],
    'error_responses' => ['error_responses', 'none'],
    'tags' => ['tags', ['default_strategy' => 'none']],
    'content' => ['content', ['dir' => 'resources/docs/other']],
    'overlays' => ['overlays', ['docs/overlays/other/*.json']],
    'representation' => ['representation', ['nullability' => 'nullable']],
    'versioning' => ['versioning', 'none'],
    'integrations' => ['integrations', ['sanctum' => ['enabled' => false]]],
]);
