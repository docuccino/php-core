<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ProvenanceTrail;

it('reports the deepest node on the path that recorded any', function (): void {
    $document = [
        'a' => [
            'x-docuccino' => ['provenance' => [['producer' => 'inference', 'layer' => 'inference']]],
            'b' => [
                'x-docuccino' => ['provenance' => [['producer' => 'attribute', 'layer' => 'attribute']]],
            ],
        ],
    ];

    expect(ProvenanceTrail::at($document, ['a', 'b'])->lines())->toBe(['attribute (attribute)'])
        ->and(ProvenanceTrail::at($document, ['a', 'b'])->pointer)->toBe('/a/b');
});

it('walks outward when the failing node records none of its own', function (): void {
    $document = [
        'a' => [
            'x-docuccino' => ['provenance' => [['producer' => 'inference', 'layer' => 'inference']]],
            'b' => ['type' => 'string'],
        ],
    ];

    expect(ProvenanceTrail::at($document, ['a', 'b', 'type'])->lines())->toBe(['inference (inference)'])
        ->and(ProvenanceTrail::at($document, ['a', 'b', 'type'])->pointer)->toBe('/a');
});

it('says nothing when nothing on the path recorded any', function (): void {
    expect(ProvenanceTrail::at(['a' => ['b' => 1]], ['a', 'b'])->isEmpty())->toBeTrue()
        ->and(ProvenanceTrail::none()->lines())->toBe([])
        ->and(ProvenanceTrail::none()->pointer)->toBe('');
});

it('renders a record with as much of the source as it carries', function (array $record, string $line): void {
    $document = ['a' => ['x-docuccino' => ['provenance' => [$record]]]];

    expect(ProvenanceTrail::at($document, ['a'])->lines())->toBe([$line]);
})->with([
    'producer and layer only' => [
        ['producer' => 'fallback', 'layer' => 'fallback'],
        'fallback (fallback)',
    ],
    'a file' => [
        ['producer' => 'attribute', 'layer' => 'attribute', 'source' => ['file' => 'app/X.php']],
        'attribute (attribute) — app/X.php',
    ],
    'a file and a line' => [
        ['producer' => 'attribute', 'layer' => 'attribute', 'source' => ['file' => 'app/X.php', 'line' => 9]],
        'attribute (attribute) — app/X.php:9',
    ],
    'a file, a line and a symbol' => [
        ['producer' => 'docblock', 'layer' => 'docblock', 'source' => ['file' => 'app/X.php', 'line' => 9, 'symbol' => 'App\X::y']],
        'docblock (docblock) — app/X.php:9 in App\X::y',
    ],
    'a source with an empty file' => [
        ['producer' => 'overlay', 'layer' => 'overlay', 'source' => ['file' => '']],
        'overlay (overlay)',
    ],
]);

it('ignores an x-docuccino member that is not shaped like one', function (mixed $docuccino): void {
    expect(ProvenanceTrail::at(['a' => ['x-docuccino' => $docuccino]], ['a'])->isEmpty())->toBeTrue();
})->with([
    'not an object' => ['nope'],
    'no provenance' => [['id' => 'op:v1:x']],
    'provenance that is not a list' => [['provenance' => 'nope']],
    'provenance holding a non-record' => [['provenance' => ['nope']]],
]);
