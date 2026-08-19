<?php

declare(strict_types=1);

use Docuccino\Core\Contract\MediaType;

it('reads the base type off a content-type header', function (?string $header, ?string $base): void {
    expect(MediaType::base($header))->toBe($base);
})->with([
    'plain' => ['application/json', 'application/json'],
    'with parameters' => ['application/json; charset=utf-8', 'application/json'],
    'upper case' => ['Application/JSON', 'application/json'],
    'padded' => ['  application/json  ', 'application/json'],
    'absent' => [null, null],
    'empty' => ['', null],
    'blank' => ['   ', null],
]);

it('knows which media types carry JSON', function (string $mediaType, bool $json): void {
    expect(MediaType::isJson($mediaType))->toBe($json);
})->with([
    'application/json' => ['application/json', true],
    'text/json' => ['text/json', true],
    'a +json suffix' => ['application/problem+json', true],
    'a vendor +json suffix' => ['application/vnd.api+json', true],
    'csv' => ['text/csv', false],
    'octet stream' => ['application/octet-stream', false],
    'something no one has heard of' => ['application/x-made-up', false],
]);

it('selects the content entry a body of that type is described by', function (array $content, ?string $requested, ?string $key): void {
    expect(MediaType::select($content, $requested))->toBe($key);
})->with([
    'an exact match' => [['application/json' => [], 'text/csv' => []], 'application/json', 'application/json'],
    'a type wildcard' => [['application/*' => []], 'application/problem+json', 'application/*'],
    'a full wildcard' => [['*/*' => []], 'text/csv', '*/*'],
    'exact beats wildcard' => [['*/*' => [], 'text/csv' => []], 'text/csv', 'text/csv'],
    'the documented key keeps its own casing' => [['Application/JSON' => []], 'application/json', 'Application/JSON'],
    'nothing describes it' => [['application/json' => []], 'text/csv', null],
    'no type declared, one entry' => [['application/json' => []], null, 'application/json'],
    'no type declared, several entries' => [['application/json' => [], 'text/csv' => []], null, null],
    'no type declared, no entries' => [[], null, null],
]);
