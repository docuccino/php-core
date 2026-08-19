<?php

declare(strict_types=1);

use Docuccino\Core\Examples\RecordedBody;

/**
 * Reading a response body without changing what it claims. `{}` and `[]` are different claims and an
 * example that swapped one for the other would contradict the schema beside it.
 */
it('reads an object as an array the drafts and emitters can carry', function (): void {
    expect(RecordedBody::decode('{"id":1,"tags":["a"]}'))->toBe(['id' => 1, 'tags' => ['a']]);
});

it('keeps an empty object an empty object', function (): void {
    expect(RecordedBody::decode('{"meta":{}}'))->toEqual(['meta' => (object) []]);
});

it('keeps an object whose member names look like indexes an object', function (): void {
    expect(RecordedBody::decode('{"by_id":{"0":"a","1":"b"}}'))
        ->toEqual(['by_id' => (object) ['0' => 'a', '1' => 'b']]);
});

it('reads a scalar body as the scalar it is', function (string $json, mixed $expected): void {
    expect(RecordedBody::decode($json))->toBe($expected);
})->with([
    'a string' => ['"ok"', 'ok'],
    'a number' => ['42', 42],
    'a float' => ['1.5', 1.5],
    'a bool' => ['true', true],
    'null' => ['null', null],
    'an empty list' => ['[]', []],
]);

it('throws rather than guess at something that is not JSON', function (): void {
    expect(static fn (): mixed => RecordedBody::decode('{oops'))->toThrow(JsonException::class);
});

it('writes a recording file that ends in a newline', function (): void {
    expect(RecordedBody::encode(['a' => 1]))->toBe("{\n    \"a\": 1\n}\n");
});

it('writes a slash and a non-ASCII character as themselves, for a file a person reads', function (): void {
    expect(RecordedBody::encode(['path' => '/api/facturé']))->toBe("{\n    \"path\": \"/api/facturé\"\n}\n");
});

it('answers null rather than half a file when a value cannot be encoded', function (): void {
    expect(RecordedBody::encode(['body' => "\xB1\x31"]))->toBeNull();
});
