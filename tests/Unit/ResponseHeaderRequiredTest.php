<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;

/**
 * `required` on a response header is what tells a consumer the server always sends it, and what the
 * contract check holds a response to. It is a Header Object member in every OAS version the emitters
 * write, so every one of them has to carry it out — a keyword dropped on the way to 3.0 would quietly
 * re-describe the header as optional for exactly the consumers reading the oldest dialect.
 */
function requiredHeaderDocument(): UirDocument
{
    return UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [
            '/a' => [
                'get' => [
                    'responses' => [
                        '429' => [
                            'description' => 'Too Many Requests',
                            'headers' => [
                                'Retry-After' => [
                                    'description' => 'Seconds to wait.',
                                    'required' => true,
                                    'schema' => ['type' => 'integer'],
                                ],
                                'X-Trace' => [
                                    'description' => 'Sometimes.',
                                    'schema' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'components' => [
            'headers' => [
                'RetryAfter' => [
                    'description' => 'Seconds to wait.',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ],
            ],
        ],
    ]);
}

it('carries a response header\'s required out through every emitter', function (callable $emit): void {
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($emit(requiredHeaderDocument()), true, flags: JSON_THROW_ON_ERROR);

    $headers = $decoded['paths']['/a']['get']['responses']['429']['headers'];

    expect($headers['Retry-After']['required'])->toBeTrue()
        // The one beside it says nothing, and stays saying nothing: OAS defaults `required` to false, so
        // an emitter that helpfully wrote it out would put a member in every header ever published.
        ->and($headers['X-Trace'])->not->toHaveKey('required')
        ->and($decoded['components']['headers']['RetryAfter']['required'])->toBeTrue();
})->with([
    'uir' => [fn (UirDocument $d): string => (new UirEmitter)->emit($d)],
    'openapi 3.2' => [fn (UirDocument $d): string => (new OpenApi32Emitter)->emit($d)],
    'openapi 3.1' => [fn (UirDocument $d): string => (new OpenApi31DownlevelEmitter)->emit($d)],
    'openapi 3.0' => [fn (UirDocument $d): string => (new OpenApi30DownlevelEmitter)->emit($d)],
]);

it('canonicalizes required into the place the Header Object gives it', function (): void {
    // Member order is part of the byte-identical output, so where it lands is a fact rather than an
    // accident of which producer wrote it.
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((new UirEmitter)->emit(requiredHeaderDocument()), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($decoded['paths']['/a']['get']['responses']['429']['headers']['Retry-After']))
        ->toBe(['description', 'required', 'schema']);
});
