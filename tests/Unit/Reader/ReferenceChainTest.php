<?php

declare(strict_types=1);

use Docuccino\Core\Contract\RefHops;
use Docuccino\Core\Reader\DocumentNode;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\UnreadableDocumentException;

/*
 * A reference is followed once per document, however many nodes use it. A chain read again at every use
 * costs every hop's length at every use: 20,000 operations using one parameter whose own reference is
 * 400 KB long, 3 MB of JSON, took 6.3 s to read and as long again to list.
 *
 * Each document here uses one chain 4,000 times, its long hops 1.6 MB together, so reading the chain afresh
 * at every use reads 6.4 GB of pointer. Followed once, the pointer read is the same for 4,000 uses as for
 * one, which is what these count ({@see RefHops::pointerBytes()}) rather than how long it takes.
 */

/**
 * $uses paths using one chain of references in $bucket: `A`, then $hops long references, the last landing
 * on $target. Each path is $use with `{use}` standing for its number.
 */
$chained = static function (string $bucket, int $hops, array $target, Closure $use, int $uses): string {
    $long = static fn (int $hop): string => str_repeat(chr(ord('a') + $hop), intdiv(1_600_000, $hops));
    $chain = ['A' => ['$ref' => "#/components/{$bucket}/".$long(1)], $long($hops) => $target];
    for ($hop = 1; $hop < $hops; $hop++) {
        $chain[$long($hop)] = ['$ref' => "#/components/{$bucket}/".$long($hop + 1)];
    }

    $paths = [];
    for ($i = 1; $i <= $uses; $i++) {
        $paths["/p{$i}"] = $use($i);
    }

    return (string) json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Chained', 'version' => '1'],
        'paths' => $paths,
        'components' => [$bucket => $chain],
    ], JSON_UNESCAPED_SLASHES);
};

$ok = ['200' => ['description' => 'OK']];
$parameter = static fn (int $hops, int $uses) => $chained('parameters', $hops, ['name' => 'q', 'in' => 'query'], static fn (int $i): array => ['get' => [
    'parameters' => [['$ref' => '#/components/parameters/A']],
    'responses' => $ok,
]], $uses);

/**
 * What reading, listing and modelling $bytes read of pointer, and the nodes it lists.
 *
 * @return array{int, list<DocumentNode>}
 */
$followed = static function (string $bytes): array {
    $before = RefHops::pointerBytes();
    $document = (new DocumentReader)->read($bytes);
    $listed = $document->nodes();
    $document->uir();

    return [RefHops::pointerBytes() - $before, $listed];
};

it('reads, lists and models a document using one long chain many times over, following the chain once', function (Closure $bytes, int $hops, int $nodes, string $key, ?string $label, ?string $identity) use ($followed): void {
    [$read, $listed] = $followed($bytes(4_000));
    [$once] = $followed($bytes(1));

    // Each answer the chain's landing decides: the use is listed as the node it lands on, wherever it does.
    $use = array_values(array_filter($listed, static fn (DocumentNode $node): bool => $node->key === $key));

    // The chain was read at all, each long hop at least once, and no more for 4,000 uses than for one.
    expect($once)->toBeGreaterThanOrEqual(min($hops, 7) * intdiv(1_600_000, $hops))
        ->and($read)->toBe($once)
        ->and($listed)->toHaveCount($nodes)
        ->and($use)->toHaveCount(1)
        ->and($use[0]->label ?? null)->toBe($label)
        ->and($use[0]->identity ?? null)->toBe($identity);
})->with([
    'a parameter, one long hop on' => [fn (int $uses) => $parameter(1, $uses), 1, 12_002, 'GET /p1 query:q', 'q', null],
    'a parameter, four long hops on' => [fn (int $uses) => $parameter(4, $uses), 4, 12_005, 'GET /p1 query:q', 'q', null],
    'a parameter, seven long hops on: eight, the most a chain may take' => [fn (int $uses) => $parameter(7, $uses), 7, 12_008, 'GET /p1 query:q', 'q', null],
    // One hop too many lands nowhere, at every use, as it always did: each use is listed by its reference.
    'a parameter, eight long hops on: one more than a chain may take' => [fn (int $uses) => $parameter(8, $uses), 8, 12_009, 'GET /p1 $ref:#/components/parameters/A', null, null],
    // Every operation carries its use's id, so putting each back beside its `$ref` follows every chain too.
    'a parameter each operation keeps its use of' => [fn (int $uses) => $chained('parameters', 1, ['name' => 'q', 'in' => 'query'], static fn (int $i): array => ['get' => [
        'parameters' => [['$ref' => '#/components/parameters/A']],
        'responses' => $ok,
        'x-docuccino' => ['uses' => ['parameters' => ['query' => ['q' => ['id' => sprintf('par:v1:%016x', $i)]]]]],
    ]], $uses), 1, 12_002, 'GET /p1 query:q', 'q', 'par:v1:0000000000000001'],
    'a response, one long hop on' => [fn (int $uses) => $chained('responses', 1, ['description' => 'OK'], static fn (int $i): array => ['get' => [
        'responses' => ['200' => ['$ref' => '#/components/responses/A']],
    ]], $uses), 1, 8_002, 'GET /p1 200', 'OK', null],
]);

it('refuses a document whose paths all use one long chain to a path item, without following it at each', function (): void {
    // Every path lists the item's operation at the end of a 1.6 MB pointer: gigabytes of index, refused,
    // and finding that out follows the chain, and spells where it lands, once.
    $document = static fn (int $paths): string => (string) json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Chained', 'version' => '1'],
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/p{$i}", range(1, $paths)), ['$ref' => '#/components/pathItems/A']),
        'components' => ['pathItems' => [
            'A' => ['$ref' => '#/components/pathItems/'.str_repeat('p', 1_600_000)],
            str_repeat('p', 1_600_000) => ['get' => ['responses' => ['200' => ['description' => 'OK']]]],
        ]],
    ], JSON_UNESCAPED_SLASHES);

    $before = RefHops::pointerBytes();
    (new DocumentReader)->read($document(1));
    $once = RefHops::pointerBytes() - $before;

    $bytes = $document(4_000);
    $before = RefHops::pointerBytes();

    try {
        (new DocumentReader)->read($bytes);
        $this->fail('A document whose index spells gigabytes was read.');
    } catch (UnreadableDocumentException $exception) {
        // One path reads the long pointer and spells where it lands, and 4,000 read and spell no more.
        expect($exception->failure)->toBe(ReadFailure::NodeExpansion)
            ->and($exception->reason)->toContain(sprintf('spells more than the %d bytes its size allows', 16 * strlen($bytes)))
            ->and($once)->toBeGreaterThanOrEqual(2 * 1_600_000)
            ->and(RefHops::pointerBytes() - $before)->toBe($once);
    }
});
