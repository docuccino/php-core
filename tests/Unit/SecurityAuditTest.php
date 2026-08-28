<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Pipeline\SecurityAudit;

/*
 * The security family: a `security` requirement names its scheme by key rather than by `$ref`, so the
 * audit is the only thing between an author's typo and a document OpenAPI calls invalid. The dataset
 * below is the whole family — every case that reports, every case that deliberately does not, and the
 * malformed shapes it has to degrade through rather than throw on.
 */
it('reports exactly the codes a document earns', function (array $document, array $expected): void {
    $reported = array_map(
        static fn (Diagnostic $d): string => $d->code.'/'.$d->severity->value,
        SecurityAudit::report($document),
    );

    expect($reported)->toBe($expected);
})->with([
    'an operation naming an undefined scheme' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth' => []]]]]],
        ],
        ['security.undefined-scheme/error'],
    ],
    'an operation naming a scheme the document defines' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth' => []]]]]],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ],
        [],
    ],
    'a document-level requirement naming an undefined scheme' => [
        [
            'security' => [['bearerAuth' => []]],
            'components' => ['securitySchemes' => ['apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key']]],
        ],
        ['security.undefined-scheme/error'],
    ],
    'a webhook naming an undefined scheme' => [
        [
            'webhooks' => ['invoice.paid' => ['post' => ['security' => [['bearerAuth' => []]]]]],
        ],
        ['security.undefined-scheme/error'],
    ],
    'two names in one requirement, both undefined' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['apiKey' => [], 'bearerAuth' => []]]]]],
        ],
        ['security.undefined-scheme/error', 'security.undefined-scheme/error'],
    ],
    'one undefined scheme named by two operations' => [
        [
            'paths' => [
                '/invoices' => ['get' => ['security' => [['bearerAuth' => []]]]],
                '/payments' => ['get' => ['security' => [['bearerAuth' => []]]]],
            ],
        ],
        ['security.undefined-scheme/error'],
    ],
    'an OAuth2 requirement asking for an undeclared scope' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.write']]]]]],
            'components' => ['securitySchemes' => ['oauth2' => ['type' => 'oauth2', 'flows' => [
                'authorizationCode' => ['scopes' => ['invoices.read' => 'Read invoices']],
            ]]]],
        ],
        ['security.undeclared-scope/warning'],
    ],
    'an OAuth2 requirement asking for a scope another flow declares' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.write']]]]]],
            'components' => ['securitySchemes' => ['oauth2' => ['type' => 'oauth2', 'flows' => [
                'authorizationCode' => ['scopes' => ['invoices.read' => 'Read invoices']],
                'clientCredentials' => ['scopes' => ['invoices.write' => 'Write invoices']],
            ]]]],
        ],
        [],
    ],
    'an OAuth2 scheme whose flows declare nothing at all' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.read']]]]]],
            'components' => ['securitySchemes' => ['oauth2' => ['type' => 'oauth2', 'flows' => ['implicit' => ['scopes' => []]]]]],
        ],
        ['security.undeclared-scope/warning'],
    ],
    'an OAuth2 scheme with no flows member to read' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.read']]]]]],
            'components' => ['securitySchemes' => ['oauth2' => ['type' => 'oauth2']]],
        ],
        [],
    ],
    // The scopes an OIDC scheme accepts live in its discovery document, which the build never fetches.
    'an OpenID Connect requirement carrying scopes' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oidc' => ['email', 'profile']]]]]],
            'components' => ['securitySchemes' => ['oidc' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://example.test/.well-known/openid-configuration']]],
        ],
        [],
    ],
    // OAS 3.1+ reads a non-OAuth2 scope list as role names "not otherwise defined or exchanged in-band".
    'an HTTP scheme carrying a role-name list' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth' => ['admin']]]]]],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ],
        [],
    ],
    // Deliberately silent: a scheme nothing references is dead weight, not a wrong statement.
    'a scheme the document defines and nothing references' => [
        [
            'paths' => ['/invoices' => ['get' => []]],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ],
        [],
    ],
    'the anonymous requirement' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [[], ['bearerAuth' => []]]]]],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ],
        [],
    ],
    'an explicitly public operation' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => []]]],
        ],
        [],
    ],
    'a document with no security anywhere' => [
        [
            'paths' => ['/invoices' => ['get' => ['operationId' => 'invoices.index']]],
        ],
        [],
    ],
    // Degradation: none of these is a Security Requirement Object, and none of them is this audit's
    // report to make — it says nothing rather than inventing a missing scheme out of a malformed one.
    'security that is not a list' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => 'bearerAuth']]],
        ],
        [],
    ],
    'a requirement that is not an object' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => ['bearerAuth']]]],
        ],
        [],
    ],
    // A Security Requirement Object keys by scheme NAME, so a positional key states no name at all.
    // Read as one it invented the scheme "0" and failed the build over a typo nobody had made; the
    // shape itself is already a `document.schema-invalid` error at the pointer that locates it.
    'a list-shaped requirement, whose entry is a scheme where the scopes go' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth']]]]],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ],
        [],
    ],
    'a list-shaped requirement naming nothing the document defines' => [
        [
            'security' => [['bearerAuth']],
        ],
        [],
    ],
    // ...and the positional key is skipped rather than the requirement it sits in: the named half of a
    // half-written requirement is still a claim the document makes.
    'a positional entry beside a named one' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth', 'apiKey' => []]]]]],
        ],
        ['security.undefined-scheme/error'],
    ],
    // The catalogue entry may be a Reference Object wherever a Security Scheme Object is written. Read
    // raw, a hoisted oauth2 scheme has no `type` on the node and every scope under it went unchecked.
    'an OAuth2 scheme written as a Reference Object' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.write']]]]]],
            'components' => ['securitySchemes' => [
                'oauth2' => ['$ref' => '#/components/securitySchemes/shared'],
                'shared' => ['type' => 'oauth2', 'flows' => ['authorizationCode' => ['scopes' => ['invoices.read' => 'Read invoices']]]],
            ]],
        ],
        ['security.undeclared-scope/warning'],
    ],
    'a Reference Object whose scheme declares the scope asked for' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.read']]]]]],
            'components' => ['securitySchemes' => [
                'oauth2' => ['$ref' => '#/components/securitySchemes/shared'],
                'shared' => ['type' => 'oauth2', 'flows' => ['authorizationCode' => ['scopes' => ['invoices.read' => 'Read invoices']]]],
            ]],
        ],
        [],
    ],
    // A reference that lands somewhere other than an OAuth2 scheme is read as what it lands on.
    'a Reference Object at a scheme whose scopes the document does not carry' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth' => ['admin']]]]]],
            'components' => ['securitySchemes' => [
                'bearerAuth' => ['$ref' => '#/components/securitySchemes/shared'],
                'shared' => ['type' => 'http', 'scheme' => 'bearer'],
            ]],
        ],
        [],
    ],
    // Degradation, not a guess: an unresolvable reference says nothing about scopes, and a broken
    // `$ref` is its own report to make rather than this one's.
    'a Reference Object pointing at nothing' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.write']]]]]],
            'components' => ['securitySchemes' => ['oauth2' => ['$ref' => '#/components/securitySchemes/gone']]],
        ],
        [],
    ],
    'a Reference Object chain that never lands' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.write']]]]]],
            'components' => ['securitySchemes' => [
                'oauth2' => ['$ref' => '#/components/securitySchemes/loop'],
                'loop' => ['$ref' => '#/components/securitySchemes/oauth2'],
            ]],
        ],
        [],
    ],
    'scopes that are not a list of strings' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['ok', 7, ['nested']]]]]]],
            'components' => ['securitySchemes' => ['oauth2' => ['type' => 'oauth2', 'flows' => [
                'implicit' => ['scopes' => ['ok' => 'Fine']],
            ]]]],
        ],
        [],
    ],
    'a securitySchemes bucket that is not a map' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth' => []]]]]],
            'components' => ['securitySchemes' => 'nonsense'],
        ],
        ['security.undefined-scheme/error'],
    ],
    'a components member that is not an object' => [
        [
            'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth' => []]]]]],
            'components' => 'nonsense',
        ],
        ['security.undefined-scheme/error'],
    ],
    'nothing at all' => [[], []],
]);

it('names the scheme, the site and every scheme the document does define', function (): void {
    $diagnostics = SecurityAudit::report([
        'paths' => ['/invoices' => ['get' => ['security' => [['bearerAuth' => []]]]]],
        'components' => ['securitySchemes' => [
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key'],
            'session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'laravel_session'],
        ]],
    ]);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('GET /invoices')
        ->and($diagnostics[0]->message)->toContain('"bearerAuth"')
        ->and($diagnostics[0]->help)->toContain('(apiKey, session)');
});

it('says so plainly when the document defines no schemes at all', function (): void {
    $diagnostics = SecurityAudit::report([
        'security' => [['bearerAuth' => []]],
    ]);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toStartWith('The document-level security requirement')
        ->and($diagnostics[0]->help)->toContain('defines no security schemes at all');
});

it('names the scopes a hoisted scheme offers, read through the reference', function (): void {
    $diagnostics = SecurityAudit::report([
        'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.delete']]]]]],
        'components' => ['securitySchemes' => [
            'oauth2' => ['$ref' => '#/components/securitySchemes/shared'],
            'shared' => ['type' => 'oauth2', 'flows' => [
                'authorizationCode' => ['scopes' => ['invoices.write' => 'Write', 'invoices.read' => 'Read']],
            ]],
        ]],
    ]);

    // The message names the scheme by the key the requirement wrote, and the help by what it resolves to.
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('"oauth2"')
        ->and($diagnostics[0]->help)->toContain('(invoices.read, invoices.write)');
});

it('names the scopes the scheme does offer', function (): void {
    $diagnostics = SecurityAudit::report([
        'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['invoices.delete']]]]]],
        'components' => ['securitySchemes' => ['oauth2' => ['type' => 'oauth2', 'flows' => [
            'authorizationCode' => ['scopes' => ['invoices.write' => 'Write', 'invoices.read' => 'Read']],
        ]]]],
    ]);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->help)->toContain('(invoices.read, invoices.write)');
});

/*
 * The report is a function of the NAMES, not of the order the sites were met in. The document-level
 * requirement is read before any operation and an operation before the webhooks, so encounter order and
 * name order genuinely differ — and a report that followed encounter order would be rewritten by a route
 * added on the other side of the application.
 */
it('reports undefined schemes in name order, not in the order the sites were met', function (): void {
    $messages = array_map(static fn (Diagnostic $d): string => $d->message, SecurityAudit::report([
        'security' => [['zulu' => []]],
        'paths' => ['/invoices' => ['get' => ['security' => [['mike' => []]]]]],
        'webhooks' => ['invoice.paid' => ['post' => ['security' => [['alpha' => []]]]]],
    ]));

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toContain('"alpha"')
        ->and($messages[1])->toContain('"mike"')
        ->and($messages[2])->toContain('"zulu"')
        // ...and the site each one names is still its own, not the one it was sorted next to.
        ->and($messages[0])->toContain('POST webhooks.invoice.paid')
        ->and($messages[2])->toStartWith('The document-level security requirement');
});

it('reports undeclared scopes in name order, not in the order the requirement lists them', function (): void {
    $messages = array_map(static fn (Diagnostic $d): string => $d->message, SecurityAudit::report([
        'paths' => ['/invoices' => ['get' => ['security' => [['oauth2' => ['zulu', 'mike', 'alpha']]]]]],
        'components' => ['securitySchemes' => ['oauth2' => ['type' => 'oauth2', 'flows' => [
            'implicit' => ['scopes' => []],
        ]]]],
    ]));

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toContain('"alpha"')
        ->and($messages[1])->toContain('"mike"')
        ->and($messages[2])->toContain('"zulu"');
});

/*
 * Anti-vacuity. The dataset above is only worth the rows that actually report, and a report() that
 * returned [] unconditionally would satisfy most of them.
 */
it('reports something for a plausible number of the dataset rows', function (): void {
    $reporting = 0;

    foreach ([
        ['paths' => ['/a' => ['get' => ['security' => [['x' => []]]]]]],
        ['security' => [['x' => []]]],
        ['webhooks' => ['w' => ['post' => ['security' => [['x' => []]]]]]],
        ['paths' => ['/a' => ['get' => ['security' => [['o' => ['s']]]]]], 'components' => ['securitySchemes' => ['o' => ['type' => 'oauth2', 'flows' => ['implicit' => ['scopes' => []]]]]]],
    ] as $document) {
        $reporting += SecurityAudit::report($document) === [] ? 0 : 1;
    }

    expect($reporting)->toBe(4);
});
