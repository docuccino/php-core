<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\Postman\CollectionEmitter;

/**
 * One policy for one fact, across every producer that reads a document's `servers`.
 *
 * `default` is REQUIRED on a Server Variable Object by OpenAPI 3.0, 3.1 and 3.2 alike, so a variable
 * without one made the artifact invalid in all three — silently, because only the Postman emitter had
 * anything to say about it. The remedy differs by target (a document can leave a variable out; a
 * collection has to publish something) and the diagnostic does not.
 */

/**
 * The document's `servers` as one format emits them, plus the diagnostics that emission raised.
 *
 * @param  list<array<string, mixed>>  $servers
 * @return array{list<mixed>, list<Diagnostic>}
 */
function emittedServers(string $format, array $servers): array
{
    $result = Formats::emit($format, UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'servers' => $servers,
        'paths' => ['/things' => ['get' => ['responses' => ['204' => ['description' => 'No content']]]]],
    ]), new EmitOptions);

    /** @var array{servers?: list<mixed>} $decoded */
    $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    return [$decoded['servers'] ?? [], $result->report->diagnostics];
}

/** Every OpenAPI format, since the requirement is every version's, not one version's. */
function openApiFormats(): array
{
    return [
        '3.2' => ['openapi-3.2'],
        '3.1' => ['openapi-3.1'],
        '3.0' => ['openapi-3.0'],
    ];
}

it('leaves a variable that declares a usable default alone', function (string $format): void {
    [$servers, $diagnostics] = emittedServers($format, [[
        'url' => 'https://{tenant}.example.com',
        'variables' => ['tenant' => ['default' => 'acme', 'enum' => ['acme', 'globex']]],
    ]]);

    expect($servers[0]['variables']['tenant'])->toBe(['enum' => ['acme', 'globex'], 'default' => 'acme'])
        ->and($diagnostics)->toBe([]);
})->with(openApiFormats());

it('stands the first of the declared enum values in as the default', function (string $format): void {
    // The enum is the API's own closed set, so a member of it resolves the URL to something the server
    // really serves — which is why this is a substitution rather than an invention.
    [$servers, $diagnostics] = emittedServers($format, [[
        'url' => 'https://api.example.com/{version}',
        'variables' => ['version' => ['enum' => ['v1', 'v2']]],
    ]]);

    expect($servers[0]['variables']['version'])->toBe(['enum' => ['v1', 'v2'], 'default' => 'v1'])
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('server.variable-no-default')
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->message)->toContain('`version`')
        ->and($diagnostics[0]->message)->toContain('(`v1`)');
})->with(openApiFormats());

it('leaves out a variable no value in the document is legal for', function (string $format): void {
    // Nothing here names a value, and inventing one would change what the URL resolves to — a wrong
    // server URL being worse than a variable the reader has to ask about. So the variable goes, and the
    // `variables` member goes with it rather than staying behind as an empty map.
    [$servers, $diagnostics] = emittedServers($format, [[
        'url' => 'https://{tenant}.example.com',
        'variables' => ['tenant' => ['description' => 'Your tenant']],
    ]]);

    expect($servers[0])->toBe(['url' => 'https://{tenant}.example.com'])
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('server.variable-no-default')
        ->and($diagnostics[0]->message)->toContain('left out of the emitted document');
})->with(openApiFormats());

it('keeps the variables beside the one it left out', function (string $format): void {
    [$servers, $diagnostics] = emittedServers($format, [[
        'url' => 'https://{tenant}.example.com/{version}',
        'variables' => [
            'tenant' => ['description' => 'Your tenant'],
            'version' => ['default' => 'v1'],
        ],
    ]]);

    expect($servers[0]['variables'])->toBe(['version' => ['default' => 'v1']])
        ->and($diagnostics)->toHaveCount(1);
})->with(openApiFormats());

it('reads a default no URL can be built from as no default at all', function (mixed $default, ?array $expected): void {
    // An empty string resolves the template to a URL nobody serves, and a non-string is not a `default`
    // any version accepts, so both leave the reader exactly where declaring nothing does.
    [$servers, $diagnostics] = emittedServers('openapi-3.2', [[
        'url' => 'https://api.example.com/{version}',
        'variables' => ['version' => ['default' => $default]],
    ]]);

    expect($servers[0]['variables'] ?? null)->toBe($expected)
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('server.variable-no-default');
})->with([
    'an empty string' => ['', null],
    'a number' => [3, null],
    'null' => [null, null],
]);

it('falls through to leaving the variable out when the enum names nothing usable', function (array $enum): void {
    [$servers, $diagnostics] = emittedServers('openapi-3.2', [[
        'url' => 'https://api.example.com/{version}',
        'variables' => ['version' => ['enum' => $enum]],
    ]]);

    expect($servers[0])->toBe(['url' => 'https://api.example.com/{version}'])
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('left out of the emitted document');
})->with([
    'an empty enum' => [[]],
    'a first entry that is not a string' => [[7, 'v2']],
    'a first entry that is an empty string' => [['', 'v2']],
]);

it('says nothing about a server with no variables at all', function (string $format): void {
    [$servers, $diagnostics] = emittedServers($format, [['url' => 'https://api.example.com']]);

    expect($servers[0])->toBe(['url' => 'https://api.example.com'])
        ->and($diagnostics)->toBe([]);
})->with(openApiFormats());

it('names the variables in name order, never in the order a config listed them', function (): void {
    [, $diagnostics] = emittedServers('openapi-3.2', [[
        'url' => 'https://{zone}.example.com/{alpha}',
        'variables' => ['zone' => ['enum' => ['eu']], 'alpha' => ['enum' => ['a']]],
    ]]);

    expect(array_map(static fn (Diagnostic $d): string => $d->message, $diagnostics))->toHaveCount(2)
        ->and($diagnostics[0]->message)->toContain('`alpha`')
        ->and($diagnostics[1]->message)->toContain('`zone`');
});

it('raises the same code from the OpenAPI emitters and from a Postman collection', function (): void {
    $document = UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'servers' => [['url' => 'https://api.example.com/{version}', 'variables' => ['version' => ['enum' => ['v1']]]]],
        'paths' => ['/things' => ['get' => ['responses' => ['204' => ['description' => 'No content']]]]],
    ]);

    $codes = static fn (array $diagnostics): array => array_values(array_unique(array_map(
        static fn (Diagnostic $d): string => $d->code,
        $diagnostics,
    )));

    $postman = (new CollectionEmitter)->emitWithReport($document, new EmitOptions);

    expect($codes($postman->report->diagnostics))->toContain('server.variable-no-default');

    foreach (array_keys(openApiFormats()) as $version) {
        [, $diagnostics] = emittedServers('openapi-'.$version, [
            ['url' => 'https://api.example.com/{version}', 'variables' => ['version' => ['enum' => ['v1']]]],
        ]);

        expect($codes($diagnostics))->toBe(['server.variable-no-default'], $version);
    }
});
