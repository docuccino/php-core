<?php

declare(strict_types=1);

use Docuccino\Core\Support\Json;

/**
 * `Json::stable()` is what every equality and cache-key question in the build is settled by, and its
 * callers hand it arbitrary values — an extension's own properties, a schema an integration built. So
 * the contract that matters is that it is TOTAL: `''` for anything `json_encode` refuses is one
 * fingerprint shared by every such value, so two different configurations key alike and a warm cache
 * answers one with the other's output.
 *
 * The other half is the descent bound. A self-referential array is a stack overflow, which is SIGSEGV
 * and exit 139 — no exception, no message, no diagnostic.
 */
it('fingerprints every value kind json_encode refuses, rather than collapsing to nothing', function (Closure $make): void {
    expect(Json::stable($make()))->not->toBe('');
})->with([
    'bytes that are not valid UTF-8' => [fn (): array => ["\xB1\x31"]],
    'a non-UTF-8 array KEY' => [fn (): array => ["\xB1\x31" => 'x']],
    'INF' => [fn (): array => [INF]],
    '-INF' => [fn (): array => [-INF]],
    'NAN' => [fn (): array => [NAN]],
    'a resource' => [fn (): array => [fopen('php://memory', 'r')]],
    // …and the ordinary ones, which must keep working.
    'a nested array' => [fn (): array => ['a' => ['b' => [1, 2, 3]]]],
    'an object' => [fn (): array => [new stdClass]],
    'null' => [fn (): mixed => null],
    'an empty string' => [fn (): string => ''],
]);

it('tells two values of one unencodable kind apart', function (Closure $a, Closure $b): void {
    expect(Json::stable($a()))->not->toBe(Json::stable($b()));
})->with([
    'two binary blobs' => [fn (): array => ["\xB1\x31"], fn (): array => ["\xB1\x32"]],
    'two non-UTF-8 keys' => [fn (): array => ["\xB1\x31" => 1], fn (): array => ["\xB1\x32" => 1]],
    'INF against -INF' => [fn (): array => [INF], fn (): array => [-INF]],
    'INF against NAN' => [fn (): array => [INF], fn (): array => [NAN]],
    'a blob against the empty array' => [fn (): array => ["\xB1\x31"], fn (): array => []],
]);

it('reads a resource as its type, the way a closure reads as its class', function (): void {
    // Neither has a serialisable identity, so two of one kind are one fingerprint. Stated rather than
    // implied: it is the same trade the object rule already makes, and the alternative is `''`.
    expect(Json::stable([fopen('php://memory', 'r')]))->toBe(Json::stable([fopen('php://memory', 'r')]))
        ->and(Json::stable([fn (): int => 1]))->toBe(Json::stable([fn (): int => 2]));
});

it('descends into a stdClass, which is the one object whose members are its identity', function (): void {
    // A JSON object whose keys an array cannot carry — `{"1": …}` from a keyBy() payload, or an empty
    // one — travels as a stdClass. Collapsing it to its class name made every such body one
    // fingerprint, which is how a ranked recording stopped being decided by its content.
    $a = (object) ['1' => ['id' => 1], '2' => ['id' => 2]];
    $b = (object) ['7' => ['id' => 7], '9' => ['id' => 9]];

    expect(Json::stable($a))->not->toBe(Json::stable($b))
        ->and(Json::stable($a))->toBe(Json::stable((object) ['2' => ['id' => 2], '1' => ['id' => 1]]))
        // `{}` and `[]` are different claims, so they are different fingerprints.
        ->and(Json::stable(new stdClass))->not->toBe(Json::stable([]))
        // …and one nested inside an ordinary array descends too.
        ->and(Json::stable(['meta' => (object) ['a' => 1]]))->not->toBe(Json::stable(['meta' => (object) ['a' => 2]]));
});

it('reads two structurally-equal values as one fingerprint whatever order they were built in', function (): void {
    // The whole point of the normaliser, and the thing the totality fix must not have broken.
    expect(Json::stable(['b' => 2, 'a' => 1]))->toBe(Json::stable(['a' => 1, 'b' => 2]))
        // …while list order still counts, because it is what gets published.
        ->and(Json::stable([1, 2]))->not->toBe(Json::stable([2, 1]));
});

it('answers a self-referential array instead of overflowing the stack', function (): void {
    // Without the bound this is SIGSEGV, exit 139: no exception to catch and nothing in the output.
    $cycle = ['x' => 1];
    $cycle['self'] = &$cycle;

    expect(Json::stable($cycle))->not->toBe('')
        ->and(Json::stable($cycle))->toBe(Json::stable($cycle));
});

it('stops at the depth bound rather than encoding for ever', function (): void {
    // Two structures that differ only below the bound are one fingerprint. That is the cost of the
    // bound, and it is stated here so it is a decision rather than a surprise.
    $deep = static function (int $levels, string $leaf): array {
        $node = ['leaf' => $leaf];
        for ($i = 0; $i < $levels; $i++) {
            $node = ['child' => $node];
        }

        return $node;
    };

    expect(Json::stable($deep(20, 'a')))->not->toBe(Json::stable($deep(20, 'b')))
        ->and(Json::stable($deep(400, 'a')))->toBe(Json::stable($deep(400, 'b')));
});

it('fingerprints a float identically whatever serialize_precision the host is set to', function (): void {
    // The other half of the determinism hole the configuration reader's numeric settling closes. That
    // reader settles an integral float to an int; a NON-integral one stays a float, and `1.10` in a
    // configuration file is exactly that. `json_encode` then formats it with the ambient ini, so
    // `document.configHash` — a fragment-cache key input AND a value the document publishes — differed
    // between two machines building the same commit.
    $value = ['info' => ['version' => 1.10], 'a' => 0.1, 'b' => 1e-7, 'c' => 1.0 / 3.0];

    $original = ini_get('serialize_precision');

    try {
        ini_set('serialize_precision', '17');
        $at17 = Json::stable($value);

        ini_set('serialize_precision', '6');
        $at6 = Json::stable($value);

        ini_set('serialize_precision', '-1');
        $atMinus1 = Json::stable($value);
    } finally {
        ini_set('serialize_precision', $original === false ? '-1' : $original);
    }

    expect($at17)->toBe($atMinus1)
        ->and($at6)->toBe($atMinus1)
        // Shortest round-trip, which is what the host's default already gives — so nothing a document
        // already publishes moves.
        ->and($atMinus1)->toContain('"version":1.1');
});

it('leaves serialize_precision as it found it', function (): void {
    ini_set('serialize_precision', '9');

    try {
        Json::stable(['x' => 0.1]);

        expect(ini_get('serialize_precision'))->toBe('9');
    } finally {
        ini_set('serialize_precision', '-1');
    }
});
