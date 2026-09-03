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
    // A `content` key is written by an author and returned by `select()` in the document's own case, so
    // these read the same grammar the selection did.
    'capitals' => ['Application/JSON', true],
    'a +JSON suffix in capitals' => ['application/Problem+JSON', true],
    'csv' => ['text/csv', false],
    'octet stream' => ['application/octet-stream', false],
    'something no one has heard of' => ['application/x-made-up', false],
]);

it('knows which media types carry a form', function (string $mediaType, bool $form): void {
    expect(MediaType::isForm($mediaType))->toBe($form);
})->with([
    'urlencoded' => ['application/x-www-form-urlencoded', true],
    'multipart' => ['multipart/form-data', true],
    // The two above are the whole set. Everything else is bytes, and a near miss is not one of them:
    // `multipart/mixed` carries parts with no field names, and a `+json` suffix is JSON however it
    // spells itself.
    'another multipart subtype' => ['multipart/mixed', false],
    'capitals' => ['Multipart/Form-Data', true],
    'urlencoded in capitals' => ['application/X-WWW-Form-Urlencoded', true],
    'json' => ['application/json', false],
    'a +json suffix' => ['application/vnd.api+json', false],
    'csv' => ['text/csv', false],
    'something no one has heard of' => ['application/x-made-up', false],
]);

it('never calls one media type both JSON and a form', function (string $mediaType): void {
    // The two readers decide which check a body gets, and a type both answered yes to would get the
    // one that happens to be asked first.
    expect(MediaType::isJson($mediaType) && MediaType::isForm($mediaType))->toBeFalse();
})->with([
    'application/json', 'text/json', 'application/problem+json', 'application/vnd.api+json',
    'application/x-www-form-urlencoded', 'multipart/form-data', 'multipart/mixed', 'text/csv',
    'application/octet-stream', 'application/x-made-up', 'Application/JSON', 'Multipart/Form-Data',
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
