<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Reader\DocumentDecoder;
use Docuccino\Core\Reader\DocumentNode;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\NodeManifest;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\UnreadableDocumentException;
use Docuccino\Core\Support\CompactJson;
use Docuccino\Core\Support\JsonValue;
use Symfony\Component\Yaml\Yaml;

/*
 * The node index is bounded by the document it indexes. A path item every path references, and the
 * parameters a path item lists for every operation, each list one written node many times over — so a
 * document is refused once its index passes one node per byte it takes as compact JSON, before anything
 * is listed, and within that a shared node is listed as though it were written where it is used.
 *
 * The documents here are written as compact JSON unless a test says otherwise, so the length of the text
 * is the document's size.
 */

it('refuses a document whose index expands far past its size, before listing anything', function (string $openapi, string $member, int $paths, int $parameters, bool $additional): void {
    $item = ['parameters' => array_map(static fn (int $i): array => ['name' => "q{$i}", 'in' => 'query'], range(1, $parameters))];
    $operation = ['summary' => 'x', 'responses' => ['200' => ['description' => 'OK']]];
    if ($additional) {
        $item['additionalOperations'] = array_fill_keys(array_map(static fn (int $i): string => "M{$i}", range(1, $paths)), $operation);
    } else {
        $item += array_fill_keys(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'], $operation);
    }

    $bytes = (string) json_encode([
        'openapi' => $openapi,
        'info' => ['title' => 'Shared', 'version' => '1'],
        $member => $additional ? ['/a' => ['$ref' => '#/components/pathItems/P']] : array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, $paths)), ['$ref' => '#/components/pathItems/P']),
        'components' => ['pathItems' => ['P' => $item]],
    ], JSON_UNESCAPED_SLASHES);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($bytes);
        $this->fail('A document indexing to millions of nodes was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::NodeExpansion)
            ->and($exception->reason)->toContain(sprintf('more than the %d its size allows', strlen($bytes)))
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            // Every node listed would cost a few hundred bytes, so millions of them are gigabytes.
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024);
    }
})->with([
    // 2,000 paths × 8 operations × (an operation, 2,000 parameters and a response) from ~240 KB.
    'paths sharing one path item' => ['3.1.0', 'paths', 2000, 2000, false],
    'webhooks sharing one path item' => ['3.1.0', 'webhooks', 2000, 2000, false],
    // One path, 2,000 operations each listing the item's 2,000 parameters, from ~100 KB.
    'a path item listing its parameters for each additional operation' => ['3.2.0', 'paths', 2000, 2000, true],
]);

it('admits an index of exactly its limit and refuses one node more, in both regimes', function (int $parameters, ?int $length, bool $extra, bool $admitted): void {
    // 100 paths × (an operation, its parameters and one response), so 100 × ($parameters + 2) nodes.
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Shared', 'version' => '1', 'description' => ''],
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, 100)), ['$ref' => '#/components/pathItems/P']),
        'components' => ['pathItems' => ['P' => [
            'parameters' => array_map(static fn (int $i): array => ['name' => "q{$i}", 'in' => 'query'], range(1, $parameters)),
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
        ]]],
    ];
    if ($extra) {
        $document['components']['schemas'] = ['Extra' => ['type' => 'string']];
    }
    if ($length !== null) {
        $document['info']['description'] = str_repeat('x', $length - strlen((string) json_encode($document, JSON_UNESCAPED_SLASHES)));
    }

    $bytes = (string) json_encode($document, JSON_UNESCAPED_SLASHES);
    expect(strlen($bytes))->toBe($length ?? strlen($bytes));

    try {
        $count = count((new DocumentReader)->read($bytes)->nodes());
        expect($admitted)->toBeTrue()->and($count)->toBe(100 * ($parameters + 2) + ($extra ? 1 : 0));
    } catch (UnreadableDocumentException $exception) {
        expect($admitted)->toBeFalse()->and($exception->failure)->toBe(ReadFailure::NodeExpansion);
    }
})->with([
    // Under NodeManifest::MIN_NODES bytes, the floor is the limit.
    'the floor, exactly' => [98, null, false, true],
    'the floor, one node past' => [98, null, true, false],
    // Past it, one node per byte.
    'one node per byte, exactly' => [198, 20_000, false, true],
    'one node per byte, one byte short' => [198, 19_999, false, false],
]);

it('gives every way of writing a document the answer the JSON it is written back as gets', function (int $paths, int $parameters, ?int $length, Closure $write): void {
    // $paths × (an operation, its parameters and one response) nodes.
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Shared', 'version' => '1', 'description' => ''],
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, $paths)), ['$ref' => '#/components/pathItems/P']),
        'components' => ['pathItems' => ['P' => [
            'parameters' => array_map(static fn (int $i): array => ['name' => "q{$i}", 'in' => 'query'], range(1, $parameters)),
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
        ]]],
    ];
    if ($length !== null) {
        $document['info']['description'] = str_repeat('x', $length - strlen((string) json_encode($document, JSON_UNESCAPED_SLASHES)));
    }
    $compact = (string) json_encode($document, JSON_UNESCAPED_SLASHES);

    // What ReadDocument::json() writes for this document: what a caller stores, and reads again later.
    $json = (new CanonicalJsonSerializer)->serialize(json_decode($compact));
    $text = $write($document, $compact);

    $answer = static function (string $bytes): string {
        try {
            (new DocumentReader)->read($bytes);

            return 'read';
        } catch (UnreadableDocumentException $exception) {
            return $exception->failure->value;
        }
    };

    // One node per byte of the document as compact JSON, however it is written, and never fewer than 10,000.
    $expected = $paths * ($parameters + 2) <= max(10_000, strlen($compact)) ? 'read' : ReadFailure::NodeExpansion->value;

    expect($text)->not->toBe($json)
        ->and($answer($text))->toBe($expected)
        ->and($answer($json))->toBe($expected);
})->with([
    'at its limit' => [100, 198, 20_000],
    'one node past it' => [100, 198, 19_999],
    // 25,000 nodes: past the 13,812 bytes the document takes as compact JSON, and under both the 23,637
    // of the JSON it is written back as and the 31,584 of the same document indented four spaces.
    'past it, under the JSON it is written back as' => [250, 98, null],
])->with([
    'as compact JSON' => [static fn (array $document, string $compact): string => $compact],
    'as JSON indented four spaces' => [static fn (array $document, string $compact): string => (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)],
    'as YAML' => [static fn (array $document, string $compact): string => Yaml::dump($document, 10, 4)],
    // Unquoted wherever YAML allows it, so shorter than the compact JSON.
    'as terse YAML' => [static fn (array $document, string $compact): string => (string) preg_replace(['/"([A-Za-z_]\w*)":/', '/"([A-Za-z_]\w*)"/'], ['$1: ', '$1'], $compact)],
]);

it('reads whitespace and comments as nothing, however much of them there is', function (int $length, string $expected, Closure $pad): void {
    // 100 paths × (an operation, 198 parameters and one response): 20,000 nodes.
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Shared', 'version' => '1', 'description' => ''],
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, 100)), ['$ref' => '#/components/pathItems/P']),
        'components' => ['pathItems' => ['P' => [
            'parameters' => array_map(static fn (int $i): array => ['name' => "q{$i}", 'in' => 'query'], range(1, 198)),
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
        ]]],
    ];
    $document['info']['description'] = str_repeat('x', $length - strlen((string) json_encode($document, JSON_UNESCAPED_SLASHES)));
    $text = $pad($document, (string) json_encode($document, JSON_UNESCAPED_SLASHES));

    try {
        (new DocumentReader)->read($text);
        $answer = 'read';
    } catch (UnreadableDocumentException $exception) {
        $answer = $exception->failure->value;
    }

    expect(strlen($text))->toBeGreaterThan(5 * $length)
        ->and($answer)->toBe($expected);
})->with([
    'at its limit' => [20_000, 'read'],
    'one node past it' => [19_999, ReadFailure::NodeExpansion->value],
])->with([
    'spaces after it' => [static fn (array $document, string $compact): string => $compact.str_repeat(' ', 200_000)],
    'deep indentation' => [static fn (array $document, string $compact): string => (string) preg_replace('/^ +/m', str_repeat(' ', 128), (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))],
    'YAML comments' => [static fn (array $document, string $compact): string => str_repeat("# Nothing a reader reads.\n", 10_000).Yaml::dump($document, 10, 2)],
]);

it('does not let an alias buy an index, nor write out what the alias repeats to find that out', function (): void {
    $item = ['parameters' => array_map(static fn (int $i): array => ['name' => "q{$i}", 'in' => 'query'], range(1, 200))];
    $item += array_fill_keys(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'], ['summary' => 'x', 'responses' => ['200' => ['description' => 'OK']]]);
    $shared = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Shared', 'version' => '1'],
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, 100)), ['$ref' => '#/components/pathItems/P']),
        'components' => ['pathItems' => ['P' => $item]],
    ];

    // 161,600 nodes, beside a 10 KB string an alias names 100 times: 1 MB as JSON from 21 KB, as much text
    // as the decoder lets aliases spell from a document this small.
    $yaml = "x-text: &text '".str_repeat('x', 10_000)."'\nx-again:\n".str_repeat("  - *text\n", 100).Yaml::dump($shared, 2, 2);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($yaml);
        $this->fail('An index an alias paid for was read.');
    } catch (UnreadableDocumentException $exception) {
        // Four nodes a byte of the text it was read from, where its JSON would have allowed a million.
        expect($exception->failure)->toBe(ReadFailure::NodeExpansion)
            ->and($exception->reason)->toContain(sprintf('more than the %d its size allows', 4 * strlen($yaml)))
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024);
    }
});

it('caps a limit at the text only where an alias wrote the document', function (string $text, float $growth, bool $alias): void {
    [$graph] = (new DocumentDecoder)->decode($text, null);
    $measured = round(CompactJson::length($graph) / strlen($text), 1);

    // The text's limit binds only past this many bytes of JSON a byte of text.
    expect($measured)->toBe($growth)
        ->and($measured > (new ReflectionClassConstant(NodeManifest::class, 'MAX_GROWTH'))->getValue())->toBe($alias);
})->with([
    // The spellings a document's JSON outgrows the most, 2,000 times over: each writes a value in fewer
    // bytes than the JSON it is written back as does.
    'floats written short, as YAML' => ['{openapi: 3.1.0, x: ['.implode(',', array_fill(0, 2_000, '1e14')).']}', 3.2, false],
    'floats written short, as JSON' => ['{"openapi":"3.1.0","x":['.implode(',', array_fill(0, 2_000, '1e14')).']}', 3.2, false],
    'control characters escaped short' => ['{openapi: 3.1.0, x: "'.str_repeat('\0', 2_000).'"}', 3.0, false],
    'line separators escaped short' => ['{openapi: 3.1.0, x: "'.str_repeat('\L', 2_000).'"}', 3.0, false],
    'nulls written as ~' => ['{openapi: 3.1.0, x: ['.implode(',', array_fill(0, 2_000, '~')).']}', 2.5, false],
    'empty items' => ["openapi: 3.1.0\nx:\n".str_repeat("-\n", 2_000), 2.5, false],
    'unquoted strings' => ['{openapi: 3.1.0, x: ['.implode(',', array_fill(0, 2_000, 'a')).']}', 2.0, false],
    'dates written short' => ['{openapi: 3.1.0, x: ['.implode(',', array_fill(0, 2_000, '2024-1-1')).']}', 1.4, false],
    'a real YAML description' => [loadGolden('kitchen-sink.openapi32.yaml'), 0.7, false],
    'a string an alias names 1,000 times' => ["openapi: 3.1.0\nx-text: &text '".str_repeat('x', 1_000)."'\nx-again: [".implode(',', array_fill(0, 1_000, '*text'))."]\n", 142.6, true],
]);

it('reads a document that shares a path item a few times over', function (): void {
    $nodes = (new DocumentReader)->read((string) json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Versions', 'version' => '1'],
        'paths' => array_fill_keys(['/v1/orders', '/v2/orders', '/v3/orders'], ['$ref' => '#/components/pathItems/Orders']),
        'components' => ['pathItems' => ['Orders' => [
            'parameters' => [['name' => 'page', 'in' => 'query'], ['name' => 'tenant', 'in' => 'header']],
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
            'post' => ['responses' => ['201' => ['description' => 'Created']]],
        ]]],
    ]))->nodes();

    // Three paths × two operations × (the operation, two parameters, one response).
    expect($nodes)->toHaveCount(24)
        ->and(array_values(array_unique(array_map(static fn (DocumentNode $node): string => $node->key, $nodes))))->toHaveCount(24);
});

it('lists a path that references a path item as it would the item written in its place', function (): void {
    $item = [
        'parameters' => [
            ['name' => 'page', 'in' => 'query', 'x-docuccino-id' => 'par:v1:page'],
            ['$ref' => '#/components/parameters/Tenant'],
        ],
        'get' => [
            'summary' => 'List orders',
            'x-docuccino-id' => 'op:v1:list',
            // Restates the shared `page`, so it is listed once, from here.
            'parameters' => [['name' => 'page', 'in' => 'query', 'required' => true]],
            'responses' => ['200' => ['description' => 'OK'], '404' => ['$ref' => '#/components/responses/NotFound']],
        ],
        'post' => ['operationId' => 'createOrder', 'deprecated' => true, 'responses' => ['201' => ['description' => 'Created']]],
        'additionalOperations' => ['COPY' => ['responses' => ['default' => ['description' => 'Copied']]]],
    ];
    $components = [
        'parameters' => ['Tenant' => ['name' => 'Tenant', 'in' => 'header', 'x-docuccino-id' => 'par:v1:tenant']],
        'responses' => ['NotFound' => ['description' => 'Not found']],
    ];
    $paths = ['/orders', '/shops/{shop}/orders', '/archive/orders'];

    $read = static fn (array $document): array => (new DocumentReader)->read((string) json_encode(['openapi' => '3.2.0', 'info' => ['title' => 'Orders', 'version' => '1'], ...$document]))->nodes();
    $shared = $read([
        'paths' => array_fill_keys($paths, ['$ref' => '#/components/pathItems/Orders']),
        'components' => [...$components, 'pathItems' => ['Orders' => $item]],
    ]);
    $inline = $read(['paths' => array_fill_keys($paths, $item), 'components' => $components]);

    $listed = static fn (DocumentNode $node): array => [$node->kind, $node->key, $node->identity, $node->label, $node->deprecated, $node->digest];

    expect($shared)->toHaveCount(2 + 3 * 13)
        ->and(array_map($listed, $shared))->toBe(array_map($listed, $inline))
        // Written once, so pointed at once: where the item is written.
        ->and(array_values(array_unique(array_map(
            static fn (DocumentNode $node): string => explode('/', $node->pointer)[1].'/'.(explode('/', $node->pointer)[2] ?? ''),
            $shared,
        ))))->toBe(['components/pathItems', 'components/parameters', 'components/responses'])
        ->and(array_values(array_filter(array_map(static fn (DocumentNode $node): string => $node->pointer, $inline), static fn (string $pointer): bool => str_contains($pointer, 'pathItems'))))->toBe([]);
});

it('counts exactly the nodes it lists, for every document in the fixture trees', function (): void {
    $documents = [
        ...uirDocuments(),
        ...(array) glob(dirname(__DIR__, 3).'/tests/Fixtures/golden/*.openapi3*.{json,yaml}', GLOB_BRACE),
    ];

    $listed = 0;
    foreach ($documents as $path) {
        [$graph] = (new DocumentDecoder)->decode((string) file_get_contents((string) $path), null);
        /** @var array<string, mixed> $document */
        $document = array_map(JsonValue::normalize(...), get_object_vars($graph));

        $nodes = (new NodeManifest)->of($document);
        expect((new NodeManifest)->count($document))->toBe(count($nodes), (string) $path);
        $listed += count($nodes);
    }

    // A scan that found nothing would agree with itself forever.
    expect(count($documents))->toBeGreaterThan(40)
        ->and($listed)->toBeGreaterThan(1_000);
});
