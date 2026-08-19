<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Pointer;

it('escapes the two characters a pointer segment cannot hold', function (array $segments, string $pointer): void {
    expect(Pointer::of($segments))->toBe($pointer);
})->with([
    'nothing' => [[], ''],
    'a plain segment' => [['paths'], '/paths'],
    'a path template' => [['paths', '/api/invoices'], '/paths/~1api~1invoices'],
    'a tilde' => [['a~b'], '/a~0b'],
    'both, in the order the RFC requires' => [['~/'], '/~0~1'],
    'an index' => [['parameters', 0], '/parameters/0'],
]);

it('unescapes back to the segment it started as', function (array $segments): void {
    // `~1` first on the way back, or an escaped `~1` returns as a `/` the author never wrote.
    foreach ($segments as $segment) {
        expect(Pointer::unescape(Pointer::escape((string) $segment)))->toBe((string) $segment);
    }
})->with([
    'the characters the RFC escapes' => [['/api/invoices', 'a~b', '~/', '~1', '~0']],
    'segments with nothing to escape' => [['paths', '', 'get', 0]],
]);

it('appends one segment to a pointer', function (): void {
    expect(Pointer::append('/paths', '/api/x'))->toBe('/paths/~1api~1x');
});

it('reads what a pointer addresses, and null for a step that is not there', function (): void {
    $document = ['a' => ['b' => ['c' => 1]]];

    expect(Pointer::read($document, ['a', 'b', 'c']))->toBe(1)
        ->and(Pointer::read($document, []))->toBe($document)
        ->and(Pointer::read($document, ['a', 'x']))->toBeNull()
        ->and(Pointer::read($document, ['a', 'b', 'c', 'd']))->toBeNull();
});

it('reads the object graph through objects and arrays alike', function (): void {
    $graph = json_decode('{"paths":{"/a":{"get":{"parameters":[{"name":"page"}]}}}}');

    expect(Pointer::readGraph($graph, ['paths', '/a', 'get', 'parameters', '0', 'name']))->toBe('page')
        ->and(Pointer::readGraph($graph, ['paths', '/a', 'get', 'parameters', '9']))->toBeNull()
        ->and(Pointer::readGraph($graph, ['nope']))->toBeNull();
});
