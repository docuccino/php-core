<?php

declare(strict_types=1);

use Docuccino\Core\Diff\DiffFailure;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Reader\DocumentReader;

/*
 * The differ reads a shared node once per use, so how much it reads is a function of how often a
 * document uses what it shares, not of how large the document is. Each document may be read through its
 * references for 32 times its own size, or 1 MiB for a small one, and past that the comparison is
 * refused rather than made — with the documents read the way an upload is.
 */

it('refuses a comparison that reads a shared node far more often than its document is large', function (Closure $document): void {
    $read = static fn (bool $changed): UirDocument => (new DocumentReader)->read((string) json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Shared', 'version' => '1'],
        ...$document($changed),
    ]))->uir();
    [$old, $new] = [$read(false), $read(true)];

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentDiffer)->diff($old, $new);
        $this->fail('The comparison was made.');
    } catch (IncomparableDocumentsException $exception) {
        expect($exception->failure)->toBe(DiffFailure::ReferenceExpansion)
            ->and($exception->getMessage())->toContain('refused rather than made')
            // Bounds on the work, not a benchmark: refused, a row stops once it has read 32 times its
            // document, which is a second or two under coverage. Made, the first four report 200,000
            // changes in hundreds of megabytes, and the last never ends.
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(10.0)
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(96 * 1024 * 1024);
    }
})->with([
    // 1,000 paths sharing one operation whose body has 200 properties; every property changes type.
    'a shared path item' => [static fn (bool $changed): array => [
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, 1000)), ['$ref' => '#/components/pathItems/P']),
        'components' => ['pathItems' => ['P' => ['post' => [
            'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => array_fill_keys(array_map(static fn (int $i): string => "p{$i}", range(1, 200)), ['type' => $changed ? 'integer' : 'string'])]]]],
            'responses' => ['204' => ['description' => 'Done']],
        ]]]],
    ]],
    // 1,000 operations using one shared response, parameter or request body, each large.
    'a shared response' => [static fn (bool $changed): array => [
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, 1000)), ['get' => ['responses' => ['200' => ['$ref' => '#/components/responses/R']]]]),
        'components' => ['responses' => ['R' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => array_fill_keys(array_map(static fn (int $i): string => "p{$i}", range(1, 200)), ['type' => $changed ? 'integer' : 'string'])]]]]]],
    ]],
    'a shared parameter' => [static fn (bool $changed): array => [
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, 1000)), ['get' => ['parameters' => [['$ref' => '#/components/parameters/Q']], 'responses' => ['204' => ['description' => 'Done']]]]),
        'components' => ['parameters' => ['Q' => ['name' => 'q', 'in' => 'query', 'schema' => ['enum' => array_map(static fn (int $i): string => "value-{$i}", range(1, $changed ? 1999 : 2000))]]]],
    ]],
    // The same response under 1,000 custom methods of one path item, which are operations like any other.
    'a shared response under custom methods' => [static fn (bool $changed): array => [
        'paths' => ['/a' => ['additionalOperations' => array_fill_keys(array_map(static fn (int $i): string => "M{$i}", range(1, 1000)), ['responses' => ['200' => ['$ref' => '#/components/responses/R']]])]],
        'components' => ['responses' => ['R' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => array_fill_keys(array_map(static fn (int $i): string => "p{$i}", range(1, 200)), ['type' => $changed ? 'integer' : 'string'])]]]]]],
    ]],
    'a shared request body' => [static fn (bool $changed): array => [
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/a{$i}", range(1, 1000)), ['post' => ['requestBody' => ['$ref' => '#/components/requestBodies/B'], 'responses' => ['204' => ['description' => 'Done']]]]),
        'components' => ['requestBodies' => ['B' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => array_fill_keys(array_map(static fn (int $i): string => "p{$i}", range(1, 200)), ['type' => $changed ? 'integer' : 'string'])]]]]]],
    ]],
    // 30 schemas, each pointing at the next twice; the new side renames every one. Compared, every
    // position resolves both sides afresh, so the walk doubles at each level: 2^30 positions from 4 KB.
    'schemas repointed at every level' => [static fn (bool $changed): array => [
        'paths' => ['/a' => ['get' => ['responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.($changed ? 'B' : 'A').'0']]]]]]]],
        'components' => ['schemas' => array_combine(
            array_map(static fn (int $i): string => ($changed ? 'B' : 'A').$i, range(0, 29)),
            array_map(static fn (int $i): array => $i === 29 ? ['type' => 'string'] : ['type' => 'object', 'properties' => [
                'left' => ['$ref' => '#/components/schemas/'.($changed ? 'B' : 'A').($i + 1)],
                'right' => ['$ref' => '#/components/schemas/'.($changed ? 'B' : 'A').($i + 1)],
            ]], range(0, 29)),
        )],
    ]],
]);

it('reads a document through its references for exactly its budget, and refuses one byte more', function (int $uses, int $response, ?int $size, bool $compared, bool $custom): void {
    // $uses operations each using one shared response $response bytes long, so a comparison reads
    // $uses × $response bytes through each document's references — whether each is a path's `get` or a
    // custom method of one path item.
    $operation = ['responses' => ['200' => ['$ref' => '#/components/responses/R']]];
    $document = [
        'openapi' => $custom ? '3.2.0' : '3.1.0',
        'info' => ['title' => 'Shared', 'version' => '1', 'description' => ''],
        'paths' => $custom
            ? ['/o' => ['additionalOperations' => array_fill_keys(array_map(static fn (int $i): string => "M{$i}", range(1, $uses)), $operation)]]
            : array_fill_keys(array_map(static fn (int $i): string => "/o{$i}", range(1, $uses)), ['get' => $operation]),
        'components' => ['responses' => ['R' => ['description' => str_repeat('x', $response - strlen('{"description":""}'))]]],
    ];
    // A document's size is its length as compact JSON.
    $measured = static fn (array $document): int => strlen((string) json_encode((new DocumentReader)->read((string) json_encode($document))->uir()->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($size !== null) {
        $document['info']['description'] = str_repeat('x', $size - $measured($document));
        expect($measured($document))->toBe($size);
    }

    $uir = (new DocumentReader)->read((string) json_encode($document))->uir();

    try {
        expect((new DocumentDiffer)->diff($uir, $uir)->changes)->toBe([])
            ->and($compared)->toBeTrue();
    } catch (IncomparableDocumentsException $exception) {
        expect($compared)->toBeFalse()
            ->and($exception->failure)->toBe(DiffFailure::ReferenceExpansion);
    }
})->with([
    // A document under 32 KiB may be read for 1 MiB whatever its size: 256 × 4,096 bytes.
    'the floor, exactly' => [256, 4096, null, true],
    'the floor, one byte per use past it' => [256, 4097, null, false],
    // Past it, 32 times its size: 1,000 × 3,000 bytes from a document of 93,750.
    'thirty-two times its size, exactly' => [1000, 3000, 93_750, true],
    'thirty-two times its size, one byte short' => [1000, 3000, 93_749, false],
])->with(['under get' => [false], 'under custom methods' => [true]]);

it('compares documents that share nodes the way real ones do', function (): void {
    // 200 operations using one shared error response and one shared parameter, and a chain of
    // repointed schemas short enough to stay under the floor.
    $document = static fn (bool $changed): UirDocument => (new DocumentReader)->read((string) json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Orders', 'version' => '1'],
        'paths' => array_fill_keys(array_map(static fn (int $i): string => "/orders/{$i}", range(1, 200)), ['get' => [
            'parameters' => [['$ref' => '#/components/parameters/Tenant']],
            'responses' => ['200' => ['description' => 'OK'], '422' => ['$ref' => '#/components/responses/Invalid']],
        ]]),
        'components' => [
            'parameters' => ['Tenant' => ['name' => 'Tenant', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'responses' => ['Invalid' => ['description' => 'Invalid', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                'message' => ['type' => 'string'],
                'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => $changed ? 'integer' : 'string']]],
            ]]]]]],
        ],
    ]))->uir();

    // The shared response's edit is reported under every operation that uses it.
    expect((new DocumentDiffer)->diff($document(false), $document(true))->changes)->toHaveCount(200);
});

it('says which refusal it is, as a value a caller can branch on', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['x-docuccino']['id'] = 'op:v2:aaaaaaaaaaaaaaaa';

    try {
        (new DocumentDiffer)->diff(UirDocument::fromArray(diffBase()), UirDocument::fromArray($new));
        $this->fail('Documents of two identity algorithms were compared.');
    } catch (IncomparableDocumentsException $exception) {
        expect($exception->failure)->toBe(DiffFailure::AlgorithmMismatch);
    }

    expect(array_map(static fn (DiffFailure $failure): string => $failure->value, DiffFailure::cases()))
        ->toBe(['algorithm-mismatch', 'reference-expansion']);
});
