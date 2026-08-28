<?php

declare(strict_types=1);

use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedBody;
use Docuccino\Core\Examples\RecordedExample;

/**
 * The recording model: which of a suite's many responses gets published, and — the determinism half —
 * when a committed body is left exactly as it is.
 */
it('publishes the body that fills in the most of the contract', function (): void {
    $sparse = RecordedExample::of('200', 'application/json', ['id' => 1, 'note' => null]);
    $full = RecordedExample::of('200', 'application/json', ['id' => 1, 'note' => 'paid in full']);

    expect($full->outranks($sparse))->toBeTrue()
        ->and($sparse->outranks($full))->toBeFalse();
});

it('prefers the shorter of two bodies that show the same amount', function (): void {
    $long = RecordedExample::of('200', 'application/json', ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaa']);
    $short = RecordedExample::of('200', 'application/json', ['id' => 'a']);

    expect($short->outranks($long))->toBeTrue();
});

it('ranks two equally good bodies the same way whichever arrives first', function (): void {
    $a = RecordedExample::of('200', 'application/json', ['id' => 'aaa']);
    $b = RecordedExample::of('200', 'application/json', ['id' => 'bbb']);

    expect($a->outranks($b))->toBeTrue()
        ->and($b->outranks($a))->toBeFalse();
});

it('ranks two object-shaped bodies apart, the same way it ranks two arrays', function (): void {
    // A JSON object whose keys an array cannot carry — the ordinary keyBy('id') payload — decodes to a
    // stdClass. Rank it by anything that reads such a body as "an object" and every candidate ties, so
    // the published example becomes whichever the ledger merged first.
    $a = RecordedBody::decode('{"1":{"id":1,"note":"aaa"},"2":{"id":2,"note":"bbb"}}');
    $b = RecordedBody::decode('{"7":{"id":7,"note":"zzz"},"9":{"id":9,"note":"yyy"}}');

    $first = RecordedExample::of('200', 'application/json', $a);
    $second = RecordedExample::of('200', 'application/json', $b);

    expect($first->outranks($second))->toBeTrue()
        ->and($second->outranks($first))->toBeFalse();
});

it('breaks a tie on the bytes it will publish, not on the fingerprint that sorts them', function (): void {
    // The same members in a different order — one model hydrated by create(), the same model hydrated
    // by find(). `Json::stable` sorts an object's members on purpose, so these fingerprint alike; rank
    // on that alone and they tie, and a tie is settled by which test ran first while the file each
    // would write differs.
    $created = RecordedExample::of('200', 'application/json', RecordedBody::decode('{"id":1,"title":"Intake"}'));
    $found = RecordedExample::of('200', 'application/json', RecordedBody::decode('{"title":"Intake","id":1}'));

    expect($created->outranks($found))->toBeTrue()
        ->and($found->outranks($created))->toBeFalse();
});

it('leaves a committed body alone while its shape is unchanged', function (): void {
    $committed = RecordedExample::of('200', 'application/json', ['id' => 1, 'created_at' => '2026-01-01T00:00:00Z']);
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [$committed]);

    $rerecorded = $recording->with(
        RecordedExample::of('200', 'application/json', ['id' => 9, 'created_at' => '2026-08-18T09:14:02Z']),
    );

    expect($rerecorded->toArray())->toBe($recording->toArray());
});

it('leaves a committed body alone while only the ids it is keyed BY move', function (): void {
    // The ordinary keyBy('id') payload, which decodes to an object: the ids are the member names, so a
    // rule that read key TEXT would find every re-recording a new shape and rewrite the file every run.
    $keyedBy = static fn (string $first, string $second): mixed => RecordedBody::decode(
        '{"'.$first.'":{"name":"Core details"},"'.$second.'":{"name":"Contact details"}}',
    );

    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('200', 'application/json', $keyedBy(
            '0193a1f0-0000-7000-8000-000000000001',
            '0193a1f0-0000-7000-8000-000000000002',
        )),
    ]);

    $rerecorded = $recording->with(RecordedExample::of('200', 'application/json', $keyedBy(
        '3fa85f64-5717-4562-b3fc-2c963f66afa6',
        'c0ffee00-dead-4bee-8000-000000000001',
    )));

    // A third id is a member the contract did not have, and that still rewrites the file — the rule is
    // "which ids" rather than "id-keyed bodies never move".
    $grown = $recording->with(RecordedExample::of('200', 'application/json', RecordedBody::decode(
        '{"3fa85f64-5717-4562-b3fc-2c963f66afa6":{"name":"a"},"c0ffee00-dead-4bee-8000-000000000001":{"name":"b"},"f47ac10b-58cc-1372-a567-0e02b2c3d479":{"name":"c"}}',
    )));

    expect($rerecorded->toArray())->toBe($recording->toArray())
        ->and($grown->toArray())->not->toBe($recording->toArray());
});

it('replaces a committed body when the shape really did move', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    ]);

    $rerecorded = $recording->with(RecordedExample::of('200', 'application/json', ['id' => 1, 'total' => 10]));

    expect($rerecorded->find('200', 'application/json')?->body)->toBe(['id' => 1, 'total' => 10])
        ->and($rerecorded->responses)->toHaveCount(1);
});

it('keeps a response this run never exercised', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('404', 'application/json', ['message' => 'Not found.']),
    ]);

    $rerecorded = $recording->with(RecordedExample::of('200', 'application/json', ['id' => 1]));

    expect(array_map(static fn (RecordedExample $e): string => $e->key(), $rerecorded->responses))
        ->toBe(['200 application/json', '404 application/json']);
});

it('orders its responses by status and media type, never by arrival', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /x', [
        RecordedExample::of('404', 'application/problem+json', []),
        RecordedExample::of('200', 'application/json', []),
        RecordedExample::of('200', 'application/hal+json', []),
    ]);

    expect(array_map(static fn (RecordedExample $e): string => $e->key(), $recording->responses))
        ->toBe(['200 application/hal+json', '200 application/json', '404 application/problem+json']);
});

it('round-trips through its own array form', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    ]);

    expect(ExampleRecording::fromArray($recording->toArray())?->toArray())->toBe($recording->toArray());
});

it('refuses anything that is not a recording it understands', function (array $data): void {
    expect(ExampleRecording::fromArray($data))->toBeNull();
})->with([
    'no format marker' => [['operation' => 'op:v1:abcdefgh12345678', 'responses' => []]],
    'a format from the future' => [['docuccino' => 'recording/9', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => []]],
    'no operation' => [['docuccino' => 'recording/1', 'responses' => []]],
    'an empty operation' => [['docuccino' => 'recording/1', 'operation' => '', 'responses' => []]],
    'no responses' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678']],
    'responses that are not a list' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => 'many']],
    'a response that is not an object' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => ['x']]],
    'a response with no status' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => [['mediaType' => 'application/json', 'body' => []]]]],
    'a response with no media type' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => [['status' => '200', 'body' => []]]]],
    'a response with no body' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => [['status' => '200', 'mediaType' => 'application/json']]]],
]);

it('takes a null body as a body, since a response may legitimately be null', function (): void {
    $recording = ExampleRecording::fromArray([
        'docuccino' => 'recording/1',
        'operation' => 'op:v1:abcdefgh12345678',
        'responses' => [['status' => '200', 'mediaType' => 'application/json', 'body' => null]],
    ]);

    expect($recording?->responses[0]->body)->toBeNull();
});

it('takes a missing endpoint label as no label rather than as a broken file', function (): void {
    $recording = ExampleRecording::fromArray([
        'docuccino' => 'recording/1',
        'operation' => 'op:v1:abcdefgh12345678',
        'responses' => [],
    ]);

    expect($recording?->endpoint)->toBe('');
});

it('keeps one body per name, and orders them by name inside their media type', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/carts', [
        RecordedExample::of('200', 'application/json', ['items' => [['sku' => 'A']]], 'full-cart'),
        RecordedExample::of('200', 'application/json', ['items' => []], 'empty-cart'),
    ]);

    expect(array_map(static fn (RecordedExample $e): string => $e->key(), $recording->responses))
        ->toBe(['200 application/json empty-cart', '200 application/json full-cart'])
        ->and($recording->find('200', 'application/json', 'empty-cart')?->body)->toBe(['items' => []]);
});

it('drops the unnamed body of a media type a named one has taken over', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/carts', [
        RecordedExample::of('200', 'application/json', ['items' => []]),
        RecordedExample::of('404', 'application/json', ['message' => 'No cart.']),
    ]);

    $named = $recording->with(RecordedExample::of('200', 'application/json', ['items' => []], 'empty-cart'));

    // OpenAPI carries `example` or `examples` and never both, so a file keeping the unnamed body would
    // be keeping one the document could not publish. The other status is nobody's business but its own.
    expect(array_map(static fn (RecordedExample $e): string => $e->key(), $named->responses))
        ->toBe(['200 application/json empty-cart', '404 application/json']);
});

it('answers the same way whichever of the two was recorded first', function (): void {
    $unnamed = RecordedExample::of('200', 'application/json', ['items' => []]);
    $named = RecordedExample::of('200', 'application/json', ['items' => [['sku' => 'A']]], 'full-cart');

    $one = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/carts', [$unnamed, $named]);
    $other = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/carts', [$named, $unnamed]);

    expect($one->toArray())->toBe($other->toArray());
});

it('reads and writes a name, and spells none where there is none', function (): void {
    $named = RecordedExample::of('200', 'application/json', ['id' => 1], 'empty-cart')->toArray();

    expect($named)->toBe(['status' => '200', 'mediaType' => 'application/json', 'name' => 'empty-cart', 'body' => ['id' => 1]])
        ->and(RecordedExample::of('200', 'application/json', ['id' => 1])->toArray())->not->toHaveKey('name')
        ->and(RecordedExample::fromArray($named)?->name)->toBe('empty-cart');
});

it('takes a name a document could not carry as a broken file', function (mixed $name): void {
    expect(ExampleRecording::fromArray([
        'docuccino' => 'recording/1',
        'operation' => 'op:v1:abcdefgh12345678',
        'responses' => [['status' => '200', 'mediaType' => 'application/json', 'name' => $name, 'body' => []]],
    ]))->toBeNull();
})->with([
    'one with a space in it' => ['empty cart'],
    'one starting with a dash' => ['-empty'],
    'one with a slash in it' => ['carts/empty'],
    'one past sixty-four characters' => [str_repeat('a', 65)],
    'one that is not a string' => [7],
]);

it('names what may be a name and what may not', function (string $name, bool $legal): void {
    expect(RecordedExample::isLegalName($name))->toBe($legal);
})->with([
    'a plain word' => ['empty', true],
    'a dashed pair' => ['empty-cart', true],
    'dots and underscores' => ['cart.v2_final', true],
    'a leading digit' => ['2xx', true],
    'sixty-four characters' => [str_repeat('a', 64), true],
    'sixty-five' => [str_repeat('a', 65), false],
    'nothing at all' => ['', false],
    'a space' => ['empty cart', false],
    'a leading dot' => ['.empty', false],
    'a brace' => ['{empty}', false],
]);
