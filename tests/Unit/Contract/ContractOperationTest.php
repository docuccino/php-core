<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Coverage\CoverageLog;

/*
 * The operation's status grammar, read from both ends. `responseKeyFor()` picks the response a status
 * was checked against; `responseKeys()` lists what a coverage denominator counts. They are one grammar
 * or they are a number nobody can ever reach: a key the list carries and the lookup cannot select is a
 * row that stays unexercised forever, and `--min=100` never passes again.
 */
dataset('response keys', [
    // key, the key responseKeys() lists it as (null where no status can reach it), a status to probe
    'an exact code' => ['200', '200', 200],
    'an exact code outside 2xx' => ['422', '422', 422],
    'an uppercase range' => ['4XX', '4XX', 404],
    'default' => ['default', 'default', 599],
    'a lower-case range, which OAS does not define' => ['4xx', null, 404],
    'a mixed-case range' => ['4Xx', null, 404],
    'a word' => ['twohundred', null, 200],
    'a two-digit code' => ['20', null, 200],
    'a four-digit code' => ['1000', null, 1000],
    'the empty key' => ['', null, 200],
]);

it('lists a documented response key only where a status can reach it', function (string $key, ?string $listed, int $status): void {
    $operation = contractOperationDocumenting([$key => ['description' => 'x']]);

    expect($operation->responseKeys())->toBe($listed === null ? [] : [$listed])
        ->and($operation->unreachableResponseKeys())->toBe($listed === null ? [$key] : [])
        ->and($operation->responseKeyFor($status))->toBe($listed);
})->with('response keys');

it('puts every documented response on one side of the grammar or the other', function (): void {
    // The dataset above proves the rows it lists. This reads the document instead: a key family nobody
    // thought of must land in one of the two lists, never fall out of both — which is how a response
    // silently leaves the denominator and the reader's view at once.
    $keys = ['200', '4XX', 'default', '4xx', 'twohundred', ''];

    $responses = array_combine($keys, array_fill(0, count($keys), ['description' => 'x']));
    // Not a response object at all, so it is neither promise nor defect — it is not a response.
    $responses['broken'] = 'not a response object';

    $operation = contractOperationDocumenting($responses);

    $partition = [...$operation->responseKeys(), ...$operation->unreachableResponseKeys()];

    sort($keys);
    sort($partition);

    expect($partition)->toBe($keys);
});

it('orders documented keys by family and by status, whatever order the document wrote them', function (): void {
    // Status order, not alphabetical: `5XX` sorts where `500` would rather than after `1XX`, so the
    // "it documents …" message reads down the status range the way a reader expects it to.
    expect(contractOperationDocumenting([
        'default' => ['description' => 'x'],
        '5XX' => ['description' => 'x'],
        '500' => ['description' => 'x'],
        '1XX' => ['description' => 'x'],
        '404' => ['description' => 'x'],
        '200' => ['description' => 'x'],
    ])->documentedStatuses())->toBe('200, 404, 500, 1XX, 5XX, default')
        ->and(contractOperationDocumenting([
            '500' => ['description' => 'x'],
            '5XX' => ['description' => 'x'],
            '1XX' => ['description' => 'x'],
        ])->documentedStatuses())->toBe('500, 1XX, 5XX');
});

it('resolves a status outside the three-digit range to default alone, never to a range', function (): void {
    // Reading the first digit of 1000 as a family answers `1XX`, which the coverage log cannot carry —
    // so the checker would call the response exercised and the report could never agree.
    $ranged = contractOperationDocumenting(['1XX' => ['description' => 'x']]);

    expect($ranged->responseKeyFor(1000))->toBeNull()
        ->and($ranged->responseKeyFor(99))->toBeNull()
        ->and(contractOperationDocumenting([
            '1XX' => ['description' => 'x'],
            'default' => ['description' => 'x'],
        ])->responseKeyFor(1000))->toBe('default')
        ->and(CoverageLog::entry('op:v1:0123456789abcdef', 1000))->toBe('op:v1:0123456789abcdef');
});

it('prefers the exact code, then the range, then default', function (): void {
    $operation = contractOperationDocumenting([
        '404' => ['description' => 'x'],
        '4XX' => ['description' => 'x'],
        'default' => ['description' => 'x'],
    ]);

    expect($operation->responseKeyFor(404))->toBe('404')
        ->and($operation->responseKeyFor(422))->toBe('4XX')
        ->and($operation->responseKeyFor(200))->toBe('default');
});

it('reaches every status it lists, so a hundred per cent floor is reachable', function (): void {
    // The defect this file exists for: one unreachable key in the denominator and no run can ever clear
    // a 100% floor, however completely the suite exercises the endpoint.
    $operation = contractOperationDocumenting([
        '200' => ['description' => 'x'],
        '4XX' => ['description' => 'x'],
        'default' => ['description' => 'x'],
        '4xx' => ['description' => 'x'],
        'twohundred' => ['description' => 'x'],
    ]);

    $reached = [];
    for ($status = 100; $status <= 599; $status++) {
        $key = $operation->responseKeyFor($status);

        if ($key !== null) {
            $reached[$key] = true;
        }
    }

    expect($operation->responseKeys())->not->toBeEmpty()
        ->and(array_keys($reached))->toEqualCanonicalizing($operation->responseKeys());
});
