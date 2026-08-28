<?php

declare(strict_types=1);

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Lint\LintOperation;

/**
 * The walk the completeness lints share. Every HTTP method the spec model knows, and the degradation
 * paths a hand-written or overlaid document can put in front of it.
 */
it('finds an operation under every method a path item can carry', function (string $method): void {
    $operations = LintOperation::all(['paths' => ['/api/thing' => [$method => ['summary' => 'A thing.']]]]);

    expect($operations)->toHaveCount(1)
        ->and($operations[0]->signature)->toBe(strtoupper($method).' /api/thing');
})->with(PathItem::METHODS);

it('skips a path item member that is not an operation', function (): void {
    $operations = LintOperation::all(['paths' => ['/api/thing' => [
        'summary' => 'A path item summary, not an operation.',
        'parameters' => [['name' => 'id']],
        'get' => [],
    ]]]);

    expect($operations)->toHaveCount(1)
        ->and($operations[0]->signature)->toBe('GET /api/thing');
});

it('degrades to nothing on a document with no readable paths', function (mixed $paths): void {
    expect(LintOperation::all($paths === null ? [] : ['paths' => $paths]))->toBe([]);
})->with([
    'absent' => [null],
    'not a map' => ['/api/thing'],
    'path items that are not maps' => [['/api/thing' => 'get']],
    'operations that are not maps' => [['/api/thing' => ['get' => 'ok']]],
]);

it('reads the operationId only when it is a usable string', function (mixed $operationId, ?string $expected): void {
    expect((new LintOperation('GET /x', $operationId === null ? [] : ['operationId' => $operationId]))->operationId())->toBe($expected);
})->with([
    'absent' => [null, null],
    'empty' => ['', null],
    'number' => [42, null],
    'usable' => ['users.index', 'users.index'],
]);

it('reads a source out of the first provenance record that recorded one', function (mixed $extension, ?string $expected): void {
    $source = (new LintOperation('GET /x', $extension === null ? [] : ['x-docuccino' => $extension]))->source();

    expect($source?->file)->toBe($expected);
})->with([
    'no extension member' => [null, null],
    'no provenance' => [['id' => 'op:v1:aaaaaaaaaaaaaaaa'], null],
    'provenance that is not a list' => [['provenance' => 'inference'], null],
    'records with no source' => [['provenance' => [['producer' => 'fallback']]], null],
    'a source with no file' => [['provenance' => [['source' => ['line' => 9]]]], null],
    'the first record carrying one' => [['provenance' => [['producer' => 'fallback'], ['source' => ['file' => 'app/A.php']], ['source' => ['file' => 'app/B.php']]]], 'app/A.php'],
]);

it('finds a webhook under every method a path item can carry, named the way the differ names it', function (string $method): void {
    $operations = LintOperation::all(['webhooks' => ['invoice.paid' => [$method => ['summary' => 'Paid.']]]]);

    expect($operations)->toHaveCount(1)
        ->and($operations[0]->signature)->toBe(strtoupper($method).' webhooks.invoice.paid')
        ->and($operations[0]->webhook)->toBeTrue();
})->with(PathItem::METHODS);

it('degrades to nothing on a document with no readable webhooks', function (mixed $webhooks): void {
    expect(LintOperation::all(['webhooks' => $webhooks]))->toBe([]);
})->with([
    'not a map' => ['invoice.paid'],
    'items that are not maps' => [['invoice.paid' => 'post']],
    'operations that are not maps' => [['invoice.paid' => ['post' => 'ok']]],
]);

it('answers the paths before the webhooks, each in signature order', function (): void {
    $operations = LintOperation::all([
        'webhooks' => ['z.late' => ['post' => []], 'a.early' => ['post' => []]],
        'paths' => ['/api/z' => ['post' => []], '/api/a' => ['get' => []]],
    ]);

    expect(array_map(static fn (LintOperation $o): string => $o->signature, $operations))
        ->toBe(['GET /api/a', 'POST /api/z', 'POST webhooks.a.early', 'POST webhooks.z.late'])
        ->and(array_map(static fn (LintOperation $o): bool => $o->webhook, $operations))
        ->toBe([false, false, true, true]);
});

it('adding a webhook moves nothing about the operations already there', function (): void {
    $signatures = static fn (array $document): array => array_map(
        static fn (LintOperation $o): string => $o->signature,
        LintOperation::all($document),
    );

    $before = ['paths' => ['/api/a' => ['get' => []]], 'webhooks' => ['b.happened' => ['post' => []]]];
    $after = ['paths' => ['/api/a' => ['get' => []]], 'webhooks' => ['a.happened' => ['post' => []], 'b.happened' => ['post' => []]]];

    expect($signatures($before))->toBe(['GET /api/a', 'POST webhooks.b.happened'])
        ->and($signatures($after))->toBe(['GET /api/a', 'POST webhooks.a.happened', 'POST webhooks.b.happened']);
});

/*
 * A path item may be written as a `$ref`, and every lint that walks this list would otherwise see the
 * path publishing no operations at all — which is not a document that lints clean, it is a document
 * nobody looked at.
 */
it('follows a path item written as a $ref, under paths and under webhooks', function (string $member, string $key, string $signature): void {
    $referenced = LintOperation::all([
        $member => [$key => ['$ref' => '#/components/pathItems/Shared']],
        'components' => ['pathItems' => ['Shared' => ['get' => ['summary' => 'A thing.']]]],
    ]);

    $inline = LintOperation::all([$member => [$key => ['get' => ['summary' => 'A thing.']]]]);

    expect($referenced)->toHaveCount(1)
        ->and($referenced[0]->signature)->toBe($signature)
        ->and($referenced[0]->operation)->toBe($inline[0]->operation)
        ->and($referenced[0]->webhook)->toBe($inline[0]->webhook);
})->with([
    'a path' => ['paths', '/api/thing', 'GET /api/thing'],
    'a webhook' => ['webhooks', 'thing.made', 'GET webhooks.thing.made'],
]);

it('follows a chain of path-item references and stops on a cycle', function (array $pathItems, int $expected): void {
    $operations = LintOperation::all([
        'paths' => ['/api/thing' => ['$ref' => '#/components/pathItems/A']],
        'components' => ['pathItems' => $pathItems],
    ]);

    expect($operations)->toHaveCount($expected);
})->with([
    'a chain' => [['A' => ['$ref' => '#/components/pathItems/B'], 'B' => ['get' => ['summary' => 'A thing.']]], 1],
    'a cycle' => [['A' => ['$ref' => '#/components/pathItems/B'], 'B' => ['$ref' => '#/components/pathItems/A']], 0],
    'a name nothing defines' => [['B' => ['get' => ['summary' => 'A thing.']]], 0],
]);
