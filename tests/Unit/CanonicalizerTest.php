<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Emit\UirEmitter;

/**
 * Reverses the key order of every map (associative array) at every depth while leaving
 * list order untouched, so canonicalisation must be what makes two scrambled inputs equal.
 * Contents of `x-*` members other than `x-docuccino` are left verbatim, mirroring the
 * canonicaliser's passthrough contract (their internal order is intentionally preserved).
 */
function scrambleMaps(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('scrambleMaps', $value);
    }

    $out = [];
    foreach (array_reverse($value, true) as $key => $inner) {
        $isVerbatimExtension = is_string($key) && str_starts_with($key, 'x-') && $key !== 'x-docuccino';
        $out[$key] = $isVerbatimExtension ? $inner : scrambleMaps($inner);
    }

    return $out;
}

beforeEach(function (): void {
    $this->emitter = new UirEmitter;
    $this->canonicalizer = new Canonicalizer;
});

it('canonicalises scrambled member order to byte-identical output', function (): void {
    $doc = workedExample();
    $scrambled = scrambleMaps($doc);

    expect($this->emitter->emitArray($scrambled))->toBe($this->emitter->emitArray($doc));
});

it('is idempotent: emitting canonical output again yields identical bytes', function (): void {
    $once = $this->emitter->emitArray(workedExample());
    $reparsed = json_decode(trim($once), true);
    $twice = $this->emitter->emitArray($reparsed);

    expect($twice)->toBe($once);
});

it('orders parameters by in-rank then name', function (): void {
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/a/{id}' => [
                'get' => [
                    'parameters' => [
                        ['name' => 'zeta', 'in' => 'query'],
                        ['name' => 'per_page', 'in' => 'query'],
                        ['name' => 'id', 'in' => 'path', 'required' => true],
                        ['name' => 'X-Trace', 'in' => 'header'],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);
    $parameters = $canonical['paths']['/a/{id}']['get']['parameters'];
    $order = array_map(static fn (array $p): string => $p['name'], $parameters);

    expect($order)->toBe(['id', 'per_page', 'zeta', 'X-Trace']);
});

it('orders parameters that state neither an `in` nor a name, whichever order they arrive in', function (bool $reverse): void {
    // A `{"$ref": …}` parameter states neither, so every one of them ranks and names identically; on
    // rank and name alone the canonicaliser would leave them exactly as the list was built, and two
    // builds that assembled the same referenced parameters in different orders would emit differently.
    $refs = [
        ['$ref' => '#/components/parameters/Zeta'],
        ['$ref' => '#/components/parameters/Alpha'],
        ['name' => 'id', 'in' => 'path', 'required' => true],
    ];

    $canonical = $this->canonicalizer->canonicalize([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/a/{id}' => ['get' => [
                'parameters' => $reverse ? array_reverse($refs) : $refs,
                'responses' => ['200' => ['description' => 'ok']],
            ]],
        ],
    ]);

    expect(array_map(
        static fn (array $p): string => $p['name'] ?? $p['$ref'],
        $canonical['paths']['/a/{id}']['get']['parameters'],
    ))->toBe(['id', '#/components/parameters/Alpha', '#/components/parameters/Zeta']);
})->with([false, true]);

it('sorts map keys by code point and orders methods canonically', function (): void {
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/b' => ['post' => ['responses' => []], 'get' => ['responses' => []], 'delete' => ['responses' => []]],
            '/a' => ['get' => ['responses' => []]],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect(array_keys($canonical['paths']))->toBe(['/a', '/b']);
    expect(array_keys($canonical['paths']['/b']))->toBe(['get', 'post', 'delete']);
});

it('preserves declaration order while deduplicating enum values', function (): void {
    $schema = ['type' => 'string', 'enum' => ['b', 'a', 'b', 'c', 'a']];
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => $schema]],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect($canonical['components']['schemas']['S']['enum'])->toBe(['b', 'a', 'c']);
});

it('orders map keys by unicode code point, including multibyte keys', function (): void {
    // UTF-8 byte order equals Unicode code-point order for well-formed sequences, so the
    // canonicaliser's byte-wise key sort IS the normative code-point sort (design §3). Code points:
    // A=U+0041, a=U+0061, z=U+007A, é=U+00E9, 💡=U+1F4A1.
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'S' => [
                    'type' => 'object',
                    'properties' => [
                        'é' => ['type' => 'string'],
                        'z' => ['type' => 'string'],
                        'A' => ['type' => 'string'],
                        '💡' => ['type' => 'string'],
                        'a' => ['type' => 'string'],
                        '日本語' => ['type' => 'string'],
                        'Z' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);
    $order = array_keys($canonical['components']['schemas']['S']['properties']);

    expect($order)->toBe(['A', 'Z', 'a', 'z', 'é', '日本語', '💡']);
});

it('orders tag members in OAS 3.2 Tag Object order and keeps declaration order of the list', function (): void {
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'tags' => [
            ['kind' => 'nav', 'parent' => 'Billing', 'description' => 'd', 'name' => 'Invoices', 'summary' => 's'],
            ['name' => 'Billing'],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect(array_keys($canonical['tags'][0]))->toBe(['name', 'summary', 'description', 'parent', 'kind'])
        ->and(array_column($canonical['tags'], 'name'))->toBe(['Invoices', 'Billing']);
});

it('passes unknown x-* members through verbatim but canonicalises known members', function (): void {
    $doc = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'x-vendor' => ['z' => 1, 'a' => 2],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect(array_keys($canonical))->toBe(['uir', 'openapi', 'info', 'paths', 'x-vendor']);
    expect(array_keys($canonical['info']))->toBe(['title', 'version']);
    expect($canonical['x-vendor'])->toBe(['z' => 1, 'a' => 2]);
});

it('keeps an object-valued member a JSON object even when its keys are a 0..n sequence', function (): void {
    // PHP re-coerces a numeric-string key straight back to an int, so a `properties` map keyed by a
    // tuple's indices is a PHP LIST and would serialise as `"properties": [ … ]` — not a shape any JSON
    // Schema has. Every object-valued member goes through the same sorted-map step, so this is the one
    // place that can close it whatever synthesised the keys.
    $doc = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'Tuple' => [
                    'type' => 'object',
                    'properties' => ['0' => ['type' => 'string'], '1' => ['type' => 'integer']],
                ],
            ],
        ],
    ];

    $json = (new CanonicalJsonSerializer)->serialize($this->canonicalizer->canonicalize($doc));

    expect($json)->toContain('"properties": {')
        ->and(json_decode($json, true)['components']['schemas']['Tuple']['properties'])
        ->toBe(['0' => ['type' => 'string'], '1' => ['type' => 'integer']]);
});

/**
 * `x-enumDescriptions` is keyed by enum value, so a `0,1,2` backing run makes it a PHP list — which is
 * exactly the shape it comes back as once a fragment has been through JSON. A warm build has to say
 * what a cold one says, bytes and identities both, so the object is restored here.
 */
it('restores the object shape of an object-valued x-* member a JSON round trip flattened', function (): void {
    $doc = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'Tier' => [
                    'type' => 'integer',
                    'enum' => [0, 1],
                    'x-enumDescriptions' => ['Free.', 'Paid.'],
                    'x-enum-descriptions' => ['Free.', 'Paid.'],
                ],
            ],
        ],
    ];

    $json = (new CanonicalJsonSerializer)->serialize($this->canonicalizer->canonicalize($doc));

    expect($json)->toContain('"x-enumDescriptions": {')
        // The index-parallel array is an array by contract and stays one.
        ->and($json)->toContain('"x-enum-descriptions": [')
        ->and(json_decode($json, true)['components']['schemas']['Tier']['x-enumDescriptions'])
        ->toBe(['0' => 'Free.', '1' => 'Paid.']);
});
