<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Reader\DocumentDecoder;
use Docuccino\Core\Reader\DocumentNode;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\NodeManifest;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\UnreadableDocumentException;
use Docuccino\Core\Support\JsonValue;
use Symfony\Component\Yaml\Yaml;

/*
 * The node index is bounded in the text it spells as well as in its nodes. Every node's pointer and key
 * repeat the names written above it, so one long name over many nodes costs their product with nothing
 * shared, and a node shared by many paths or operations spells its label and id again at each. A
 * document is refused once its nodes' pointers, keys, labels and ids would spell more than 16 bytes for
 * every byte it takes as compact JSON (4 MiB for a small one), counted before any of them is spelt.
 *
 * The documents here are written as compact JSON unless a test says otherwise, so the length of the text
 * is the document's size.
 */

/** What the pointers, keys, labels and ids of $nodes spell, read off the nodes themselves. */
$spelt = static fn (array $nodes): int => array_sum(array_map(
    static fn (DocumentNode $node): int => strlen($node->pointer) + strlen($node->key) + strlen($node->label ?? '') + strlen($node->identity ?? ''),
    $nodes,
));

/**
 * One path, its name $name bytes long, over $parameters parameters, written so its index spells exactly
 * the most its limit allows — or one byte more. Padded by the operation's summary, which adds a byte of
 * text and of size, and by the description, which adds a byte of size alone.
 */
$atLimit = static function (int $name, int $parameters, bool $floor, bool $past) use ($spelt): array {
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Long', 'version' => '1', 'description' => ''],
        'paths' => ['/'.str_repeat('k', $name) => ['get' => [
            'summary' => '',
            'parameters' => array_map(static fn (int $i): array => ['name' => "q{$i}", 'in' => 'query'], range(1, $parameters)),
            'responses' => ['200' => ['description' => 'OK']],
        ]]],
    ];
    $write = static fn (array $document): string => (string) json_encode($document, JSON_UNESCAPED_SLASHES);

    // Read off the nodes the index lists, not off the count under test.
    $text = $spelt((new NodeManifest)->of(array_map(JsonValue::normalize(...), get_object_vars(json_decode($write($document))))));
    $size = strlen($write($document));

    if ($floor) {
        [$summary, $description] = [4_194_304 - $text + ($past ? 1 : 0), 0];
    } else {
        // A byte of summary is a byte of text and sixteen of limit, a byte of description sixteen of limit alone:
        // so the text past the limit falls 15 for each of the one and 16 for each of the other.
        $over = $text - 16 * $size - ($past ? 1 : 0);
        $summary = (16 - $over % 16) % 16;
        [$summary, $description] = [$summary, intdiv($over - 15 * $summary, 16)];
    }
    $document['paths']['/'.str_repeat('k', $name)]['get']['summary'] = str_repeat('s', $summary);
    $document['info']['description'] = str_repeat('x', $description);

    return [$document, $write($document), $text + $summary];
};

it('refuses a document whose index would spell far past its size, cheaply and before spelling any of it', function (array $document): void {
    $bytes = (string) json_encode(['openapi' => $document['openapi'] ?? '3.1.0', 'info' => ['title' => 'Long', 'version' => '1'], ...$document], JSON_UNESCAPED_SLASHES);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($bytes);
        $this->fail('A document whose index spells hundreds of megabytes was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::NodeExpansion)
            ->and($exception->reason)->toContain(sprintf('spells more than the %d bytes its size allows', max(4_194_304, 16 * strlen($bytes))))
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            // Spelt, each of these is a hundred megabytes or more.
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
    }
})->with(static function (): array {
    $long = str_repeat('k', 100_000);
    $parameters = array_map(static fn (int $i): array => ['name' => "q{$i}", 'in' => 'query'], range(1, 2_000));
    $ok = ['200' => ['description' => 'OK']];
    $paths = static fn (array $item): array => array_fill_keys(array_map(static fn (int $i): string => "/p{$i}", range(1, 1_000)), $item);
    $sharing = static fn (array $operation): array => [
        'paths' => $paths(['$ref' => '#/components/pathItems/P']),
        'components' => ['pathItems' => ['P' => ['get' => $operation]]],
    ];

    return [
        // Each parameter's pointer repeats the path's name: 400 MB from 154 KB, and 8 GB from 1 MB.
        'one long path name over many parameters' => [['paths' => ['/'.$long => ['get' => ['parameters' => $parameters, 'responses' => $ok]]]]],
        'one long path name over many additional operations' => [['openapi' => '3.2.0', 'paths' => ['/'.$long => ['additionalOperations' => array_fill_keys(array_map(static fn (int $i): string => "M{$i}", range(1, 2_000)), ['responses' => $ok])]]]],
        // The pointers are short, the item being written elsewhere; every key repeats the path's name.
        'a long path name over the path item it references' => [['paths' => ['/'.$long => ['$ref' => '#/components/pathItems/P']], 'components' => ['pathItems' => ['P' => ['get' => ['parameters' => $parameters, 'responses' => $ok]]]]]],
        // Every use's key, and its label, repeat the name: never spelt even to count it.
        'a parameter with a long name, used by many operations' => [['paths' => $paths(['get' => ['parameters' => [['$ref' => '#/components/parameters/P']], 'responses' => $ok]]), 'components' => ['parameters' => ['P' => ['name' => $long, 'in' => 'query']]]]],
        'a parameter with a long location, used by many operations' => [['paths' => $paths(['get' => ['parameters' => [['$ref' => '#/components/parameters/P']], 'responses' => $ok]]), 'components' => ['parameters' => ['P' => ['name' => 'q', 'in' => $long]]]]],
        // Nothing long in any pointer or key: what each listing of the shared node labels it with.
        'a long summary on a path item many paths share' => [$sharing(['summary' => $long, 'responses' => $ok])],
        'a long id on a path item many paths share' => [$sharing(['x-docuccino-id' => 'op:'.$long, 'responses' => $ok])],
        'a long description on a response many operations share' => [['paths' => $paths(['get' => ['responses' => ['200' => ['$ref' => '#/components/responses/R']]]]), 'components' => ['responses' => ['R' => ['description' => $long]]]]],
    ];
});

it('admits exactly the text its limit allows and refuses one byte more, in both regimes', function (int $name, int $parameters, bool $floor, bool $past) use ($atLimit, $spelt): void {
    [, $bytes, $text] = $atLimit($name, $parameters, $floor, $past);

    // Under 256 KiB of document the floor is the limit; past it, 16 bytes of text per byte.
    $limit = $floor ? 4_194_304 : 16 * strlen($bytes);
    expect(strlen($bytes) < 262_144)->toBe($floor)
        ->and($text)->toBe($limit + ($past ? 1 : 0));

    try {
        $nodes = (new DocumentReader)->read($bytes)->nodes();
        expect($past)->toBeFalse()
            ->and($spelt($nodes))->toBe($text);
    } catch (UnreadableDocumentException $exception) {
        expect($past)->toBeTrue()
            ->and($exception->failure)->toBe(ReadFailure::NodeExpansion)
            ->and($exception->reason)->toContain(sprintf('spells more than the %d bytes its size allows', $limit));
    }
})->with([
    // 1,000 parameters under a 2 KB name: 4 MB of index from 31 KB, and a summary making up the rest.
    'the floor, exactly' => [2_000, 1_000, true, false],
    'the floor, one byte past' => [2_000, 1_000, true, true],
    // 140 parameters under a 20 KB name: 5.6 MB of index, and a description making the document 350 KB.
    'sixteen per byte, exactly' => [20_000, 140, false, false],
    'sixteen per byte, one byte past' => [20_000, 140, false, true],
]);

it('gives every way of writing a document the answer the JSON it is written back as gets', function (bool $past, Closure $write) use ($atLimit): void {
    [$document, $compact] = $atLimit(20_000, 140, false, $past);

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

    $expected = $past ? ReadFailure::NodeExpansion->value : 'read';

    expect($text)->not->toBe($json)
        ->and($answer($text))->toBe($expected)
        ->and($answer($json))->toBe($expected);
})->with([
    'at its limit' => [false],
    'one byte past it' => [true],
])->with([
    'as compact JSON' => [static fn (array $document, string $compact): string => $compact],
    'as JSON indented four spaces' => [static fn (array $document, string $compact): string => (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)],
    'as YAML' => [static fn (array $document, string $compact): string => Yaml::dump($document, 10, 4)],
    'as terse YAML' => [static fn (array $document, string $compact): string => (string) preg_replace(['/"([A-Za-z_]\w*)":/', '/"([A-Za-z_]\w*)"/'], ['$1: ', '$1'], $compact)],
    'padded with spaces' => [static fn (array $document, string $compact): string => $compact.str_repeat(' ', 2_000_000)],
]);

it('counts exactly the text it lists, for each part of a node that spells it', function (array $document) use ($spelt): void {
    $nodes = (new NodeManifest)->of($document);

    // A guard that read the count's own arithmetic back would agree with any count.
    expect((new NodeManifest)->text($document))->toBe($spelt($nodes))
        ->and((new NodeManifest)->count($document))->toBe(count($nodes))
        ->and($nodes)->not->toBe([]);
})->with(static function (): array {
    $ok = ['200' => ['description' => 'OK']];
    $head = ['openapi' => '3.2.0', 'info' => ['title' => 'Parts', 'version' => '1']];

    return [
        'a path item two paths share, one restating a shared parameter' => [$head + [
            'paths' => ['/a/{id}' => ['$ref' => '#/components/pathItems/P'], '/b' => ['$ref' => '#/components/pathItems/P']],
            'components' => ['pathItems' => ['P' => [
                'parameters' => [['name' => 'page', 'in' => 'query'], ['name' => 'tenant', 'in' => 'header', 'x-docuccino-id' => 'par:v1:tenant']],
                'get' => ['summary' => 'List', 'parameters' => [['name' => 'page', 'in' => 'query', 'required' => true]], 'responses' => $ok],
                'post' => ['operationId' => 'create', 'x-docuccino' => ['id' => 'op:v1:create'], 'responses' => ['201' => ['description' => 'Created']]],
            ]]],
        ]],
        'shared parameters and responses, and references that land nowhere' => [$head + [
            'paths' => ['/c' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Tenant'], ['$ref' => '#/components/parameters/Missing']],
                'responses' => ['404' => ['$ref' => '#/components/responses/NotFound'], 'default' => ['$ref' => '#/components/responses/Missing']],
            ]]],
            'components' => [
                'parameters' => ['Tenant' => ['name' => 'Tenant', 'in' => 'header', 'x-docuccino-id' => 'par:v1:tenant', 'deprecated' => true]],
                'responses' => ['NotFound' => ['description' => 'Not found']],
            ],
        ]],
        'names that need escaping, and parameters written as a map' => [$head + [
            'paths' => ['/d/~{x}/e' => [
                'parameters' => ['first' => ['name' => 'a/b', 'in' => 'query'], 'x/y' => ['name' => '~c', 'in' => 'cookie']],
                'additionalOperations' => ['COPY/MOVE' => ['parameters' => [['name' => '123', 'in' => 'path']], 'responses' => ['2XX' => ['description' => 'Copied']]]],
            ]],
        ]],
        // Each key opens with where its operation is written, which for a custom one is longer than its method.
        'custom methods spelt like a fixed one, and one holding a space' => [$head + [
            'paths' => ['/f' => [
                'parameters' => [['name' => 'tenant', 'in' => 'header']],
                'get' => ['responses' => $ok],
                'additionalOperations' => ['get' => ['responses' => $ok], 'Get' => ['summary' => 'Get', 'responses' => $ok], 'GET' => ['responses' => $ok], 'C P' => ['responses' => $ok]],
            ]],
        ]],
        'webhooks, schemas, pages and workflows' => [$head + [
            'webhooks' => ['order.created' => ['post' => ['summary' => 'Created', 'responses' => $ok]]],
            'components' => ['schemas' => ['Order' => ['title' => 'An order', 'x-docuccino' => ['id' => 'sch:v1:order']], 'Bare' => true]],
            'x-docuccino' => [
                'content' => ['pages' => [['id' => 'page:v1:intro', 'slug' => 'intro', 'title' => 'Introduction'], 'not a page']],
                'workflows' => [['id' => 'checkout', 'summary' => 'Check out'], ['summary' => 'No id']],
            ],
        ]],
    ];
});

it('counts exactly the text it lists, for every document in the fixture trees', function () use ($spelt): void {
    $documents = openApiDocuments();

    $text = 0;
    foreach ($documents as $path) {
        [$graph] = (new DocumentDecoder)->decode((string) file_get_contents($path), null);
        /** @var array<string, mixed> $document */
        $document = array_map(JsonValue::normalize(...), get_object_vars($graph));

        $listed = $spelt((new NodeManifest)->of($document));
        expect((new NodeManifest)->text($document))->toBe($listed, $path);
        $text += $listed;
    }

    // A scan that found nothing would agree with itself forever.
    expect(count($documents))->toBeGreaterThan(90)
        ->and($text)->toBeGreaterThan(100_000);
});

it('counts no further than past what any limit could be, so no index outgrows the count', function (int $total, int $times, int $each, int $counted): void {
    $plus = new ReflectionMethod(NodeManifest::class, 'plus');

    expect($plus->invoke(null, $total, $times, $each, 1_000))->toBe($counted);
})->with([
    'under' => [100, 10, 50, 600],
    'exactly' => [100, 18, 50, 1_000],
    'one past' => [100, 1, 901, 1_001],
    'already past' => [1_001, 0, 0, 1_001],
    'past by more than an integer holds' => [100, PHP_INT_MAX, PHP_INT_MAX, 1_001],
    'nothing at all' => [100, PHP_INT_MAX, 0, 100],
]);
