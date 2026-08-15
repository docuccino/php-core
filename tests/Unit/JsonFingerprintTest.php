<?php

declare(strict_types=1);

use Docuccino\Core\Support\Json;

/**
 * `Json::stable()` is what every equality and cache-key question in the build is settled by, and its
 * callers hand it arbitrary values — an extension's own properties, a schema an integration built. So
 * the contract that matters is that it is TOTAL: it used to answer `''` for anything `json_encode`
 * refused, and `''` is one fingerprint shared by every such value, so two different configurations
 * keyed alike and a warm cache answered one with the other's output.
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

it('reads a resource as its type, the way an object reads as its class', function (): void {
    // Neither has a serialisable identity, so two of one kind are one fingerprint. Stated rather than
    // implied: it is the same trade the object rule already makes, and the alternative is `''`.
    expect(Json::stable([fopen('php://memory', 'r')]))->toBe(Json::stable([fopen('php://memory', 'r')]))
        ->and(Json::stable([new stdClass]))->toBe(Json::stable([new stdClass]));
});

it('reads two structurally-equal values as one fingerprint whatever order they were built in', function (): void {
    // The whole point of the normaliser, and the thing the totality fix must not have broken.
    expect(Json::stable(['b' => 2, 'a' => 1]))->toBe(Json::stable(['a' => 1, 'b' => 2]))
        // …while list order still counts, because it is what gets published.
        ->and(Json::stable([1, 2]))->not->toBe(Json::stable([2, 1]));
});

it('answers a self-referential array instead of overflowing the stack', function (): void {
    // Before the bound this was SIGSEGV, exit 139: no exception to catch and nothing in the output.
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
