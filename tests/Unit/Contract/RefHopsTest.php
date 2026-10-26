<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Contract\RefHops;
use Docuccino\Core\Reader\DocumentDecoder;
use Docuccino\Core\Reader\DocumentNode;
use Docuccino\Core\Reader\NodeManifest;
use Docuccino\Core\Support\JsonValue;

/*
 * A document's references are each followed once, however many nodes use them, and remembering where one
 * lands changes no answer: a walk gets what reading every chain afresh at every use gets, in whatever order
 * it meets them. What is remembered grows with the references a document writes, never with their uses,
 * and belongs to one document.
 */

/**
 * The chain read afresh, hop by hop, as every use read it before references were remembered — stated here
 * from RFC 6901 and the eight-hop limit rather than asked of the class under test.
 */
$afresh = static function (array $document, array $node, array $segments): array {
    for ($hop = 0; $hop < 8; $hop++) {
        $ref = $node['$ref'] ?? null;
        if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
            return [$node, $segments, null];
        }

        $target = array_map(static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment), explode('/', substr($ref, 2)));
        $resolved = $document;
        foreach ($target as $segment) {
            if (! is_array($resolved) || ! array_key_exists($segment, $resolved)) {
                return [$node, $segments, $ref];
            }
            $resolved = $resolved[$segment];
        }
        if (! is_array($resolved)) {
            return [$node, $segments, $ref];
        }

        [$node, $segments] = [$resolved, $target];
    }

    return [$node, $segments, is_string($node['$ref'] ?? null) ? $node['$ref'] : null];
};

/** Every object in $value holding a `$ref`, with the segments addressing it, in document order. */
$referrers = static function (mixed $value, array $segments = []) use (&$referrers): array {
    if (! is_array($value)) {
        return [];
    }

    $found = array_key_exists('$ref', $value) ? [[$value, $segments]] : [];
    foreach ($value as $key => $member) {
        array_push($found, ...$referrers($member, [...$segments, (string) $key]));
    }

    return $found;
};

/** The document as the reader holds it. */
$read = static function (string $path): array {
    [$graph] = (new DocumentDecoder)->decode((string) file_get_contents($path), null);

    return array_map(JsonValue::normalize(...), get_object_vars($graph));
};

it('answers what reading every chain afresh answers, for every reference in the fixture trees, in either order', function () use ($afresh, $referrers, $read): void {
    $documents = openApiDocuments();

    $followed = 0;
    $landed = 0;
    $dangling = 0;
    foreach ($documents as $path) {
        $document = $read($path);
        $uses = $referrers($document);
        $refs = new RefHops($document);

        // Twice over one memo, the second time backwards, so every answer is also given from what was remembered.
        foreach ([$uses, array_reverse($uses)] as $order) {
            foreach ($order as [$node, $segments]) {
                $expected = $afresh($document, $node, $segments);

                expect($refs->follow($node, $segments))->toBe($expected, $path.' '.Pointer::of($segments))
                    ->and($refs->followTo($node, Pointer::of($segments)))->toBe([$expected[0], Pointer::of($expected[1]), $expected[2]], $path.' '.Pointer::of($segments));
            }
        }

        foreach ($uses as [$node, $segments]) {
            [, $at, $unresolved] = $afresh($document, $node, $segments);
            $followed++;
            $landed += $at !== $segments ? 1 : 0;
            $dangling += $unresolved !== null ? 1 : 0;
        }
    }

    // A scan that found nothing would agree with itself forever: real references, landing and not (957, 955
    // and 2 as this is written).
    expect(count($documents))->toBeGreaterThan(90)
        ->and($followed)->toBeGreaterThan(500)
        ->and($landed)->toBeGreaterThan(500)
        ->and($dangling)->toBeGreaterThan(0);
});

/** A chain from `#/h1` through `h{$hops}`, which holds the node every hop before it refers on to. */
$chain = static function (int $hops): array {
    $document = ['h'.$hops => ['type' => 'string']];
    for ($i = 1; $i < $hops; $i++) {
        $document['h'.$i] = ['$ref' => '#/h'.($i + 1)];
    }

    return $document;
};

it('follows a chain of eight hops and reports a ninth as the cycle it reads as', function (int $hops, array $expected) use ($chain): void {
    expect((new RefHops($chain($hops)))->follow(['$ref' => '#/h1'], ['x']))->toBe($expected);
})->with([
    'one hop' => [1, [['type' => 'string'], ['h1'], null]],
    'eight hops, the most a chain may take' => [8, [['type' => 'string'], ['h8'], null]],
    'nine hops, reported at the reference in hand' => [9, [['$ref' => '#/h9'], ['h8'], '#/h9']],
]);

it('counts a chain\'s hops from where it starts, whatever was followed before it', function () use ($chain, $afresh): void {
    // Ten links: from h3 the chain lands in eight hops, from h2 in nine, from h1 in ten. Asked nearest first,
    // every hop past h3 is already remembered when h2 and h1 are followed.
    $document = $chain(10);
    $refs = new RefHops($document);

    foreach ([3, 2, 1, 3] as $start) {
        $node = ['$ref' => '#/h'.$start];

        expect($refs->follow($node, ['x']))->toBe($afresh($document, $node, ['x']), 'from h'.$start);
    }

    expect($refs->follow(['$ref' => '#/h3'], ['x'])[2])->toBeNull()
        ->and($refs->follow(['$ref' => '#/h2'], ['x'])[2])->toBe('#/h10');
});

it('refuses what it refused before, through one memo asked in either order', function (array $document, array $nodes) use ($afresh): void {
    $refs = new RefHops($document);

    foreach ([$nodes, array_reverse($nodes), $nodes] as $order) {
        foreach ($order as $node) {
            expect($refs->follow($node, ['x']))->toBe($afresh($document, $node, ['x']))
                ->and($refs->followTo($node, '/x'))->toBe([$afresh($document, $node, ['x'])[0], Pointer::of($afresh($document, $node, ['x'])[1]), $afresh($document, $node, ['x'])[2]]);
        }
    }
})->with([
    'a cycle, entered from each end and from outside it' => [
        ['a' => ['$ref' => '#/b'], 'b' => ['$ref' => '#/a'], 'c' => ['$ref' => '#/a']],
        [['$ref' => '#/a'], ['$ref' => '#/b'], ['$ref' => '#/c']],
    ],
    'a node referring to itself' => [['a' => ['$ref' => '#/a']], [['$ref' => '#/a']]],
    'a target nobody wrote, reached directly and a hop in' => [
        ['a' => ['$ref' => '#/missing'], 'b' => ['$ref' => '#/a']],
        [['$ref' => '#/missing'], ['$ref' => '#/a'], ['$ref' => '#/b']],
    ],
    'a pointer into a scalar, and one past it' => [
        ['a' => ['type' => 'string']],
        [['$ref' => '#/a/type'], ['$ref' => '#/a/type/deeper']],
    ],
    'a reference that is not local, or not a reference' => [
        ['a' => ['type' => 'string']],
        [['$ref' => 'other.json#/a'], ['$ref' => '#'], ['$ref' => 42], ['type' => 'string']],
    ],
    'escapes, which land where they unescape to' => [
        ['a/b' => ['c~d' => ['type' => 'string']], 'e' => ['$ref' => '#/a~1b/c~0d']],
        [['$ref' => '#/a~1b/c~0d'], ['$ref' => '#/e'], ['$ref' => '#/a~1b/c~2d']],
    ],
]);

it('remembers each reference once, however many nodes use it', function (): void {
    $long = '#/components/parameters/'.str_repeat('n', 100_000);
    $document = ['components' => ['parameters' => ['A' => ['$ref' => $long], str_repeat('n', 100_000) => ['name' => 'q', 'in' => 'query']]]];
    $refs = new RefHops($document);
    $landings = new ReflectionProperty(RefHops::class, 'landings');

    expect($refs->follow(['$ref' => '#/components/parameters/A'], []))->toBe([['name' => 'q', 'in' => 'query'], ['components', 'parameters', str_repeat('n', 100_000)], null]);
    $remembered = memory_get_usage();

    // Each use its own node and its own copy of the text, as a decoded document holds them.
    for ($use = 0; $use < 20_000; $use++) {
        $refs->follow(['$ref' => '#/components/parameters/'.chr(65)], []);
        $refs->followTo(['$ref' => '#/components/parameters/'.chr(65)], '/paths/~1p'.$use);
    }

    expect(array_keys($landings->getValue($refs)))->toBe(['#/components/parameters/A', $long])
        // The pointer the uses landed on, spelt once: nothing grows with the uses.
        ->and(memory_get_usage() - $remembered)->toBeLessThan(300_000);
});

it('belongs to one document: the same reference in another lands where that document says', function (): void {
    $document = static fn (string $in, string $name): array => [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Scoped', 'version' => '1'],
        'paths' => ['/a' => ['get' => ['parameters' => [['$ref' => '#/components/parameters/P']], 'responses' => ['200' => ['description' => 'OK']]]]],
        'components' => ['parameters' => ['P' => ['name' => $name, 'in' => $in]]],
    ];
    [$first, $second] = [$document('query', 'page'), $document('header', 'Api-Version')];

    expect((new RefHops($first))->follow(['$ref' => '#/components/parameters/P'], [])[0])->toBe(['name' => 'page', 'in' => 'query'])
        ->and((new RefHops($second))->follow(['$ref' => '#/components/parameters/P'], [])[0])->toBe(['name' => 'Api-Version', 'in' => 'header']);

    // One index read over two documents, as a caller holding one may: each lists its own parameter.
    $manifest = new NodeManifest;
    $keys = static fn (array $nodes): array => array_values(array_filter(array_map(static fn (DocumentNode $node): string => $node->key, $nodes), static fn (string $key): bool => str_starts_with($key, 'GET /a ') && ! str_ends_with($key, ' 200')));

    expect($keys($manifest->of($first)))->toBe(['GET /a query:page'])
        ->and($keys($manifest->of($second)))->toBe(['GET /a header:Api-Version'])
        ->and($keys($manifest->of($first)))->toBe(['GET /a query:page']);
});
