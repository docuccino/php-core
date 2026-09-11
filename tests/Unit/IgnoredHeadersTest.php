<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ResponseHeaders;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\IgnoredHeaders;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\Postman\CollectionEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Pipeline\IgnoredHeaderAudit;

/*
 * OpenAPI 3.2, Parameter Object §4.12.2.1: "If `in` is "header" and the `name` field is "Accept",
 * "Content-Type" or "Authorization", the parameter definition SHALL be ignored." Response Object,
 * `headers`: "If a response header is defined with the name "Content-Type", it SHALL be ignored."
 *
 * Those two sentences are quoted here and the names below are typed out from them, because every
 * assertion in this file asks a reader whether it agrees with the SPEC. A table built by asking
 * IgnoredHeaders for its own list would agree with whatever IgnoredHeaders happened to hold, and the
 * whole point of one reading is that several readers ACT on it: the Postman emitter drops the
 * declaration from a request, the contract checker declines to enforce it, and a build that let those
 * two diverge would emit an artifact its own checker disagrees with.
 */

it('reads the declarations OAS says are not declarations, and no others', function (string $name, bool $parameter, bool $responseHeader): void {
    expect(IgnoredHeaders::parameter($name))->toBe($parameter)
        ->and(IgnoredHeaders::responseHeader($name))->toBe($responseHeader);
})->with([
    'Accept' => ['Accept', true, false],
    'Content-Type' => ['Content-Type', true, true],
    'Authorization' => ['Authorization', true, false],
    // A header name is case-insensitive on the wire, so the rule cannot be spelling-sensitive.
    'a lower-cased name' => ['content-type', true, true],
    'a shouted name' => ['AUTHORIZATION', true, false],
    'a mangled name' => ['aCcEpT', true, false],
    // The negative controls: names that look adjacent and are ordinary declarations.
    'Accept-Language' => ['Accept-Language', false, false],
    'Content-Length' => ['Content-Length', false, false],
    'Cookie' => ['Cookie', false, false],
    'a name of our own' => ['X-Tenant', false, false],
    'an empty name' => ['', false, false],
]);

/*
 * The readers that ACT on the rule, asked the same question about the same document. Covering them
 * separately would prove each reads SOMETHING; what matters is that they read the same thing, so the
 * expectation here is one boolean shared by all of them.
 */
it('has every reader that acts on the rule agree about a request header declaration', function (string $name, bool $ignored): void {
    $document = [
        '$schema' => 'https://spec.docuccino.app/uir/1.0/schema.json',
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => ['/invoices' => ['get' => [
            'operationId' => 'invoices.index',
            'parameters' => [[
                'name' => $name,
                'in' => 'header',
                'required' => true,
                'description' => 'Prose only the author could have written.',
                'schema' => ['type' => 'string'],
            ]],
            'responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ]],
        ]]],
    ];

    /** @var array<string, mixed> $collection */
    $collection = json_decode((new CollectionEmitter)->emit(UirDocument::fromArray($document)), true, flags: JSON_THROW_ON_ERROR);
    $emitted = array_map(
        static fn (array $header): string => $header['description'] ?? '',
        $collection['item'][0]['request']['header'],
    );

    $checked = array_map(
        static fn ($parameter): string => $parameter->in.':'.strtolower($parameter->name),
        ContractIndex::fromArray($document)->operations()[0]->parameters,
    );

    // The Postman collection either sends the declaration's prose or it does not; the checker either
    // holds the declaration to a request or it does not. Ignored means both, or the two disagree.
    expect(in_array('Prose only the author could have written.', $emitted, true))->toBe(! $ignored)
        ->and(in_array('header:'.strtolower($name), $checked, true))->toBe(! $ignored);
})->with([
    'Accept' => ['Accept', true],
    'Content-Type' => ['Content-Type', true],
    'Authorization' => ['Authorization', true],
    'a lower-cased name' => ['authorization', true],
    'an ordinary header' => ['X-Tenant', false],
    'a name that only looks adjacent' => ['Accept-Language', false],
]);

it('has every reader that acts on the rule agree about a response header declaration', function (string $name, bool $ignored): void {
    $document = [
        '$schema' => 'https://spec.docuccino.app/uir/1.0/schema.json',
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => ['/invoices' => ['get' => [
            'operationId' => 'invoices.index',
            'responses' => ['200' => [
                'description' => 'OK',
                'headers' => [$name => [
                    'description' => 'Prose only the author could have written.',
                    'schema' => ['type' => 'string'],
                ]],
                'content' => ['application/json' => ['schema' => ['type' => 'object'], 'example' => ['id' => 1]]],
            ]],
        ]]],
    ];

    /** @var array<string, mixed> $collection */
    $collection = json_decode((new CollectionEmitter)->emit(UirDocument::fromArray($document)), true, flags: JSON_THROW_ON_ERROR);
    $emitted = array_map(
        static fn (array $header): string => $header['description'] ?? '',
        $collection['item'][0]['response'][0]['header'],
    );

    $index = ContractIndex::fromArray($document);
    $operation = $index->operations()[0];
    $checked = array_map(
        static fn ($parameter): string => strtolower($parameter->name),
        ResponseHeaders::of($index->document(), $operation->responseFor($index->document(), 200)[0] ?? [], []),
    );

    expect(in_array('Prose only the author could have written.', $emitted, true))->toBe(! $ignored)
        ->and(in_array(strtolower($name), $checked, true))->toBe(! $ignored);
})->with([
    'Content-Type' => ['Content-Type', true],
    'a lower-cased name' => ['content-type', true],
    // OAS ignores `Accept` and `Authorization` as PARAMETERS only: a response really can carry them.
    'Accept on a response' => ['Accept', false],
    'Authorization on a response' => ['Authorization', false],
    'an ordinary header' => ['X-Request-Id', false],
]);

/*
 * The list itself, held to one copy. Two readers each holding their own would pass every assertion
 * above on the day they were written and diverge the first time one of them was extended — which is
 * how the request half of the checker came to read a shorter rule than the emitter beside it.
 */
it('keeps one copy of the list, in the class that owns the reading', function (): void {
    $holders = [];
    $root = dirname(__DIR__, 4);

    foreach (['core', 'laravel', 'inference-phpstan'] as $package) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.'/php/'.$package.'/src',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($entry->getPathname());

            // The rule spelled out: all three names in one construct, in whatever case and quoting a
            // list uses. Anything matching is a second reading of the same sentence.
            if (preg_match('/[\'"]accept[\'"].{0,120}[\'"]authorization[\'"]/is', $source) === 1
                || preg_match('/[\'"]authorization[\'"].{0,120}[\'"]accept[\'"]/is', $source) === 1) {
                $holders[] = str_replace($root.'/', '', $entry->getPathname());
            }
        }
    }

    sort($holders);

    // Not `toBeEmpty()` on a filtered list: a scan that matched nothing would pass forever, so the one
    // legitimate holder is the positive control that proves the pattern still sees the shape.
    expect($holders)->toBe(['php/core/src/Document/IgnoredHeaders.php']);
});

/*
 * The audit is the answer to what the emitters and the checker leave behind. They drop the
 * declaration because the spec says to; the author is the only one who can do anything about it
 * having been written, and a diagnostic is where they will see it.
 *
 * Severity is the difference between prose lost and a fact lost — every row below is an author who
 * can act, which is the whole firing population: the diagnostic fires once per declaration WRITTEN,
 * so there is no hit where the reader can do nothing. Written is the load-bearing word, and what the
 * block below this one pins: OAS lets one parameter object be written once and pointed at from every
 * operation in the document, and a count that followed the pointers instead would hand a 400-operation
 * API 400 copies of one sentence with one edit between them.
 */
it('reports exactly the codes and severities a document earns', function (array $paths, array $rest, array $expected): void {
    $reported = array_map(
        static fn (Diagnostic $d): string => $d->code.'/'.$d->severity->value,
        IgnoredHeaderAudit::report(['paths' => $paths] + $rest),
    );

    expect($reported)->toBe($expected);
})->with([
    'a Content-Type parameter beside the body that states the media type' => [
        ['/invoices' => ['post' => [
            'parameters' => [['name' => 'Content-Type', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        ]]],
        [],
        ['document.ignored-header-declaration/info'],
    ],
    'a Content-Type parameter and no request body at all' => [
        ['/invoices' => ['post' => [
            'parameters' => [['name' => 'Content-Type', 'in' => 'header', 'schema' => ['type' => 'string']]],
        ]]],
        [],
        ['document.ignored-header-declaration/warning'],
    ],
    'an Accept parameter beside a response that names a representation' => [
        ['/invoices' => ['get' => [
            'parameters' => [['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => []]]],
        ]]],
        [],
        ['document.ignored-header-declaration/info'],
    ],
    'an Accept parameter where every response is bodiless' => [
        ['/invoices' => ['delete' => [
            'parameters' => [['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'responses' => ['204' => ['description' => 'Gone']],
        ]]],
        [],
        ['document.ignored-header-declaration/warning'],
    ],
    'an Authorization parameter on an operation that requires a scheme' => [
        ['/invoices' => ['get' => [
            'parameters' => [['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'security' => [['bearerAuth' => []]],
        ]]],
        [],
        ['document.ignored-header-declaration/info'],
    ],
    'an Authorization parameter under a document-wide requirement' => [
        ['/invoices' => ['get' => [
            'parameters' => [['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']]],
        ]]],
        ['security' => [['bearerAuth' => []]]],
        ['document.ignored-header-declaration/info'],
    ],
    'an Authorization parameter and nothing saying the endpoint is protected' => [
        ['/invoices' => ['get' => [
            'parameters' => [['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']]],
        ]]],
        [],
        ['document.ignored-header-declaration/warning'],
    ],
    'a Content-Type response header on a response that names a representation' => [
        ['/invoices' => ['get' => ['responses' => ['200' => [
            'description' => 'OK',
            'headers' => ['Content-Type' => ['schema' => ['type' => 'string']]],
            'content' => ['application/json' => []],
        ]]]]],
        [],
        ['document.ignored-header-declaration/info'],
    ],
    'a Content-Type response header on a bodiless response' => [
        ['/invoices' => ['get' => ['responses' => ['204' => [
            'description' => 'Gone',
            'headers' => ['content-type' => ['schema' => ['type' => 'string']]],
        ]]]]],
        [],
        ['document.ignored-header-declaration/warning'],
    ],
    'a declaration behind a component reference' => [
        ['/invoices' => ['get' => [
            'parameters' => [['$ref' => '#/components/parameters/Version']],
        ]]],
        ['components' => ['parameters' => ['Version' => ['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string']]]]],
        ['document.ignored-header-declaration/warning'],
    ],
    // A component two operations point at is ONE thing the author wrote, so one diagnostic — and the
    // severity is the louder of what its sites earn, because a fact lost at either of them is lost.
    // Both arrangements, because the answer is a function of the sites and not of the order they were
    // met in: whichever of them is reached first, one operation publishing the media type nowhere is
    // what the reader is owed. A fold keeping the first would pass one of these and fail the other,
    // and so would one keeping the last.
    'a component shared by a publishing operation met first and a silent one met second' => [
        [
            '/a-publishes' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Negotiated']],
                'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => []]]],
            ]],
            '/z-does-not' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Negotiated']],
                'responses' => ['204' => ['description' => 'Gone']],
            ]],
        ],
        ['components' => ['parameters' => ['Negotiated' => ['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string']]]]],
        ['document.ignored-header-declaration/warning'],
    ],
    'a component shared by a silent operation met first and a publishing one met second' => [
        [
            '/a-does-not' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Negotiated']],
                'responses' => ['204' => ['description' => 'Gone']],
            ]],
            '/z-publishes' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Negotiated']],
                'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => []]]],
            ]],
        ],
        ['components' => ['parameters' => ['Negotiated' => ['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string']]]]],
        ['document.ignored-header-declaration/warning'],
    ],
    'a component two operations share, both publishing the fact' => [
        [
            '/invoices' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Negotiated']],
                'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => []]]],
            ]],
            '/customers' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Negotiated']],
                'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => []]]],
            ]],
        ],
        ['components' => ['parameters' => ['Negotiated' => ['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string']]]]],
        ['document.ignored-header-declaration/info'],
    ],
    // A path item never softens, so a component it points at cannot either.
    'a component a path item points at' => [
        ['/invoices' => [
            'parameters' => [['$ref' => '#/components/parameters/Credential']],
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
        ]],
        [
            'security' => [['bearerAuth' => []]],
            'components' => ['parameters' => ['Credential' => ['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']]]],
        ],
        ['document.ignored-header-declaration/warning'],
    ],
    // A response written once in `components.responses` is one declaration too. Both operations reach
    // the same node, so what it carries is the component's own property and not the referrer's.
    'a response component two operations share' => [
        [
            '/invoices' => ['get' => ['responses' => ['404' => ['$ref' => '#/components/responses/Missing']]]],
            '/customers' => ['get' => ['responses' => ['404' => ['$ref' => '#/components/responses/Missing']]]],
        ],
        ['components' => ['responses' => ['Missing' => [
            'description' => 'Gone',
            'headers' => ['Content-Type' => ['schema' => ['type' => 'string']]],
        ]]]],
        ['document.ignored-header-declaration/warning'],
    ],
    // The `Accept` half reads what the responses publish, so it has to read them through a `$ref` too:
    // a hoisted response carries a representation exactly as an inline one does.
    'an Accept parameter beside a hoisted response that names a representation' => [
        ['/invoices' => ['get' => [
            'parameters' => [['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'responses' => ['200' => ['$ref' => '#/components/responses/Listing']],
        ]]],
        ['components' => ['responses' => ['Listing' => [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => ['type' => 'object']]],
        ]]]],
        ['document.ignored-header-declaration/info'],
    ],
    'a declaration on a path item, which every operation under it inherits' => [
        ['/invoices' => [
            'parameters' => [['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
        ]],
        [],
        ['document.ignored-header-declaration/warning'],
    ],
    'one operation declaring two of them, named in a fixed order' => [
        ['/invoices' => ['get' => [
            'parameters' => [
                ['name' => 'Content-Type', 'in' => 'header', 'schema' => ['type' => 'string']],
                ['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string']],
            ],
        ]]],
        [],
        ['document.ignored-header-declaration/warning', 'document.ignored-header-declaration/warning'],
    ],
    'the same names at a location OAS does not reserve them at' => [
        ['/invoices' => ['get' => [
            'parameters' => [
                ['name' => 'accept', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'authorization', 'in' => 'cookie', 'schema' => ['type' => 'string']],
            ],
        ]]],
        [],
        [],
    ],
    'an ordinary header declaration' => [
        ['/invoices' => ['get' => [
            'parameters' => [['name' => 'X-Tenant', 'in' => 'header', 'schema' => ['type' => 'string']]],
        ]]],
        [],
        [],
    ],
    'a webhook declaring one' => [
        [],
        ['webhooks' => ['invoice.paid' => ['post' => [
            'parameters' => [['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']]],
        ]]]],
        ['document.ignored-header-declaration/warning'],
    ],
    'a document with nothing in it' => [[], [], []],
]);

/*
 * How OFTEN it fires, which is the whole of whether the channel survives being read.
 *
 * The rule stated from the contract rather than from the code: OAS 3.2 §4.12.2 lets a parameter object
 * live once under `components.parameters` and be reached from every operation by `$ref`, and an author
 * who wrote it once has exactly one place to change it. So the number the reader is owed is the number
 * of declarations they WROTE, and it must not move when the number of operations reaching them does.
 *
 * Both halves are asserted on one document, because either alone is satisfiable by an audit that has
 * gone quiet: the shared count is 1 whether the rule works or nothing is reported at all, and the
 * inline count is the positive control that says the audit still sees the declaration.
 */
it('reports a shared declaration once, however many operations point at it', function (int $operations): void {
    $shared = [];
    $inline = [];

    for ($i = 0; $i < $operations; $i++) {
        $shared['/resource-'.$i] = ['get' => ['parameters' => [['$ref' => '#/components/parameters/Credential']]]];
        $inline['/resource-'.$i] = ['get' => ['parameters' => [
            ['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']],
        ]]];
    }

    $component = ['components' => ['parameters' => ['Credential' => [
        'name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string'],
    ]]]];

    expect(IgnoredHeaderAudit::report(['paths' => $shared] + $component))->toHaveCount(1)
        // Written out on each operation it IS that many declarations, each its own edit.
        ->and(IgnoredHeaderAudit::report(['paths' => $inline]))->toHaveCount($operations);
})->with([1, 2, 40]);

it('names the site a shared declaration is written at, never an operation that points at it', function (): void {
    $reported = IgnoredHeaderAudit::report([
        'paths' => [
            '/invoices' => ['get' => ['parameters' => [['$ref' => '#/components/parameters/Credential']]]],
            '/customers' => ['get' => ['parameters' => [['$ref' => '#/components/parameters/Credential']]]],
        ],
        'components' => ['parameters' => ['Credential' => [
            'name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string'],
        ]]],
    ]);

    // The pointer the author wrote in the `$ref`, so the message names something they can search for —
    // and no operation, because naming one of two referrers would send them to a file holding no
    // declaration at all.
    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain('#/components/parameters/Credential')
        ->and($reported[0]->message)->not->toContain('/invoices')
        ->and($reported[0]->message)->not->toContain('/customers');
});

it('stays silent on a shared declaration OAS reserves nothing about', function (): void {
    $paths = [];
    for ($i = 0; $i < 5; $i++) {
        $paths['/resource-'.$i] = ['get' => ['parameters' => [['$ref' => '#/components/parameters/Shared']]]];
    }

    $ordinary = ['name' => 'X-Tenant', 'in' => 'header', 'schema' => ['type' => 'string']];
    $reserved = ['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']];

    // The positive control on the same shape: silence has to be the RULE biting rather than the
    // component path having stopped being walked.
    expect(IgnoredHeaderAudit::report(['paths' => $paths, 'components' => ['parameters' => ['Shared' => $ordinary]]]))->toBe([])
        ->and(IgnoredHeaderAudit::report(['paths' => $paths, 'components' => ['parameters' => ['Shared' => $reserved]]]))->toHaveCount(1);
});

it('names the operation and the declaration, and says where the fact belongs', function (): void {
    $reported = IgnoredHeaderAudit::report(['paths' => ['/invoices' => ['get' => [
        'parameters' => [['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']]],
    ]]]])[0];

    expect($reported->message)->toContain('GET /invoices')
        ->and($reported->message)->toContain('"Authorization"')
        ->and($reported->help)->toContain('security scheme');
});

/*
 * The half of the decision the audit does NOT make. Dropping an author's explicit declaration out of
 * the document — the artifact they cannot watch us build — would substitute our reading of their
 * intent for theirs, and a conforming consumer is already told to ignore it. So it is still there,
 * and this is the test that fails if a later change decides otherwise without saying so.
 */
it('still publishes the declaration it reports', function (): void {
    $document = [
        '$schema' => 'https://spec.docuccino.app/uir/1.0/schema.json',
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => ['/invoices' => ['get' => [
            'parameters' => [[
                'name' => 'Authorization',
                'in' => 'header',
                'description' => 'Prose only the author could have written.',
                'schema' => ['type' => 'string'],
            ]],
            'responses' => ['200' => ['description' => 'OK']],
        ]]],
    ];

    expect((new UirEmitter)->emit(UirDocument::fromArray($document)))
        ->toContain('Prose only the author could have written.')
        ->and(IgnoredHeaderAudit::report($document))->toHaveCount(1);
});

/*
 * What the checker used to do with one. A required declaration OAS says to ignore turned every
 * request that did not send the header into a contract violation — against a header no conforming
 * client was ever told about, since no generated client would offer a way to set it.
 */
it('holds no request to a declaration OAS says every reader ignores', function (string $name, bool $violates): void {
    $index = ContractIndex::fromArray(['paths' => ['/invoices' => ['get' => [
        'parameters' => [['name' => $name, 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']]],
        'responses' => ['200' => ['description' => 'OK']],
    ]]]]);

    $result = (new ContractChecker($index))->check(contractExchange('GET', '/invoices', responseContentType: null));

    expect($result->request?->ok())->toBe(! $violates);
})->with([
    'Authorization' => ['Authorization', false],
    'Accept' => ['Accept', false],
    'Content-Type' => ['Content-Type', false],
    // The positive control: an ordinary required header the request did not send is still a violation,
    // so the row above is the rule biting rather than the check having stopped running.
    'an ordinary header' => ['X-Tenant', true],
]);
