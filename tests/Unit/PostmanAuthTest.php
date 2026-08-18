<?php

declare(strict_types=1);

use Docuccino\Core\Emit\Postman\Auth;

/**
 * The security-scheme mapping table: every scheme Postman can express, every one it cannot, and the
 * two choices that must not be read off JSON key order.
 */
it('maps every expressible scheme onto its Postman auth type', function (array $scheme, string $type, array $values): void {
    $block = (new Auth)->block('cred', $scheme);

    expect($block)->not->toBeNull()
        ->and($block['type'])->toBe($type)
        ->and(array_column($block[$type], 'value'))->toBe($values);
})->with([
    'http bearer' => [
        ['type' => 'http', 'scheme' => 'bearer'],
        'bearer',
        ['{{cred}}'],
    ],
    'http basic' => [
        ['type' => 'http', 'scheme' => 'basic'],
        'basic',
        ['{{credUsername}}', '{{credPassword}}'],
    ],
    'http digest' => [
        ['type' => 'http', 'scheme' => 'digest'],
        'digest',
        ['{{credUsername}}', '{{credPassword}}'],
    ],
    'http scheme is case-insensitive' => [
        ['type' => 'http', 'scheme' => 'Bearer'],
        'bearer',
        ['{{cred}}'],
    ],
    'apiKey in a header' => [
        ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
        'apikey',
        ['X-Api-Key', '{{cred}}', 'header'],
    ],
    'apiKey in the query string' => [
        ['type' => 'apiKey', 'in' => 'query', 'name' => 'api_key'],
        'apikey',
        ['api_key', '{{cred}}', 'query'],
    ],
]);

it('has nothing to offer for a scheme Postman cannot express', function (array $scheme): void {
    expect((new Auth)->block('cred', $scheme))->toBeNull()
        ->and((new Auth)->expressible($scheme))->toBeFalse();
})->with([
    'openIdConnect' => [['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://example.com/.well-known']],
    'mutualTLS' => [['type' => 'mutualTLS']],
    // A cookie key travels as a header instead, so it gets no auth block of its own.
    'apiKey in a cookie' => [['type' => 'apiKey', 'in' => 'cookie', 'name' => 'session']],
    'an http scheme Postman has no form for' => [['type' => 'http', 'scheme' => 'negotiate']],
    // The unknown-entry degradation the table owes.
    'an unrecognised type' => [['type' => 'quantum']],
    'no type at all' => [[]],
]);

it('publishes the most complete oauth2 grant declared, whatever order the flows are in', function (array $flows, string $grant): void {
    // `flows` is a MAP: reading the grant off its first key would make the credential a consumer sets
    // up depend on ordering nobody chose.
    $block = (new Auth)->block('oauth', ['type' => 'oauth2', 'flows' => $flows]);
    $by = array_column($block['oauth2'], 'value', 'key');

    expect($by['grant_type'])->toBe($grant);
})->with([
    'all four' => [[
        'implicit' => ['authorizationUrl' => 'https://a'],
        'password' => ['tokenUrl' => 'https://t'],
        'clientCredentials' => ['tokenUrl' => 'https://t'],
        'authorizationCode' => ['authorizationUrl' => 'https://a', 'tokenUrl' => 'https://t'],
    ], 'authorizationCode'],
    'reversed, same answer' => [[
        'authorizationCode' => ['authorizationUrl' => 'https://a', 'tokenUrl' => 'https://t'],
        'clientCredentials' => ['tokenUrl' => 'https://t'],
        'password' => ['tokenUrl' => 'https://t'],
        'implicit' => ['authorizationUrl' => 'https://a'],
    ], 'authorizationCode'],
    'clientCredentials beats password' => [[
        'password' => ['tokenUrl' => 'https://t'],
        'clientCredentials' => ['tokenUrl' => 'https://t'],
    ], 'clientCredentials'],
    'password beats implicit' => [[
        'implicit' => ['authorizationUrl' => 'https://a'],
        'password' => ['tokenUrl' => 'https://t'],
    ], 'password'],
    'implicit alone' => [['implicit' => ['authorizationUrl' => 'https://a']], 'implicit'],
]);

it('carries the oauth2 urls and scopes a consumer needs', function (): void {
    $block = (new Auth)->block('oauth', ['type' => 'oauth2', 'flows' => [
        'authorizationCode' => [
            'authorizationUrl' => 'https://auth.example.com/authorize',
            'tokenUrl' => 'https://auth.example.com/token',
            'refreshUrl' => 'https://auth.example.com/refresh',
            'scopes' => ['b:read' => 'B', 'a:write' => 'A'],
        ],
    ]]);

    $by = array_column($block['oauth2'], 'value', 'key');

    expect($by['authUrl'])->toBe('https://auth.example.com/authorize')
        ->and($by['accessTokenUrl'])->toBe('https://auth.example.com/token')
        ->and($by['refreshTokenUrl'])->toBe('https://auth.example.com/refresh')
        ->and($by['clientId'])->toBe('{{oauthClientId}}')
        ->and($by['clientSecret'])->toBe('{{oauthClientSecret}}')
        // Declared scopes, sorted — the map's key order is not an authored fact.
        ->and($by['scope'])->toBe('a:write b:read');
});

it('publishes only the scopes a requirement actually asks for', function (): void {
    $scheme = ['type' => 'oauth2', 'flows' => ['authorizationCode' => [
        'tokenUrl' => 'https://t',
        'scopes' => ['a:read' => 'A', 'b:write' => 'B'],
    ]]];

    $block = (new Auth)->block('oauth', $scheme, ['b:write']);

    expect(array_column($block['oauth2'], 'value', 'key')['scope'])->toBe('b:write');
});

it('has nothing to offer an oauth2 scheme declaring no flow it knows', function (): void {
    expect((new Auth)->block('oauth', ['type' => 'oauth2', 'flows' => ['deviceCode' => []]]))->toBeNull()
        ->and((new Auth)->block('oauth', ['type' => 'oauth2']))->toBeNull();
});

it('picks the requirement scheme by type preference, not by map order', function (array $requirement, string $expected): void {
    // A requirement is an AND of schemes written as a map, so its key order carries no meaning.
    $schemes = [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
        'basic' => ['type' => 'http', 'scheme' => 'basic'],
        'digest' => ['type' => 'http', 'scheme' => 'digest'],
        'key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key'],
        'oauth' => ['type' => 'oauth2', 'flows' => ['implicit' => ['authorizationUrl' => 'https://a']]],
        'oidc' => ['type' => 'openIdConnect'],
    ];

    expect((new Auth)->preferred($requirement, $schemes))->toBe($expected);
})->with([
    'bearer wins' => [['key' => [], 'bearer' => []], 'bearer'],
    'reversed, same answer' => [['bearer' => [], 'key' => []], 'bearer'],
    'oauth2 beats apiKey' => [['key' => [], 'oauth' => []], 'oauth'],
    'apiKey beats basic' => [['basic' => [], 'key' => []], 'key'],
    'basic beats digest' => [['digest' => [], 'basic' => []], 'basic'],
    'an inexpressible scheme is skipped' => [['oidc' => [], 'basic' => []], 'basic'],
]);

it('breaks a tie on the scheme key so the answer never depends on iteration order', function (): void {
    $schemes = [
        'zulu' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Z'],
        'alpha' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-A'],
    ];

    expect((new Auth)->preferred(['zulu' => [], 'alpha' => []], $schemes))->toBe('alpha')
        ->and((new Auth)->preferred(['alpha' => [], 'zulu' => []], $schemes))->toBe('alpha');
});

it('has no preference among schemes it cannot express', function (): void {
    $schemes = ['oidc' => ['type' => 'openIdConnect'], 'mtls' => ['type' => 'mutualTLS']];

    expect((new Auth)->preferred(['oidc' => [], 'mtls' => []], $schemes))->toBeNull()
        ->and((new Auth)->preferred(['ghost' => []], $schemes))->toBeNull()
        ->and((new Auth)->preferred([], $schemes))->toBeNull();
});
