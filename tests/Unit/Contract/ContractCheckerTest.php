<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;

it('passes a response that matches the documented schema', function (): void {
    $result = checkContract(contractExchange(
        'GET',
        '/api/invoices/42',
        responseBody: '{"reference":"INV-1","total":12.5}',
    ));

    expect($result->matched())->toBeTrue()
        ->and($result->ok())->toBeTrue()
        ->and($result->failures())->toBe([]);
});

it('reports no operation for an exchange nothing documents', function (): void {
    $result = checkContract(contractExchange('GET', '/api/credits'));

    expect($result->matched())->toBeFalse()
        ->and($result->operation)->toBeNull()
        ->and($result->ok())->toBeFalse();
});

it('names the failing member, the schema node and who wrote it', function (): void {
    $result = checkContract(contractExchange(
        'GET',
        '/api/invoices/42',
        responseBody: '{"reference":"INV-1","total":"12.50"}',
    ));

    $violation = $result->response->violations[0];

    expect($violation->where())->toBe('the response body at /total')
        ->and($violation->message)->toContain('must match the type: number')
        ->and($violation->schemaPointer)->toBe('/components/schemas/Invoice/properties/total')
        ->and($violation->provenance->lines())
        ->toBe(['integration:eloquent (integration) — app/Models/Invoice.php:31 in App\Models\Invoice::$total']);
});

it('follows a $ref through a recursive component without unrolling it', function (): void {
    $result = checkContract(contractExchange(
        'GET',
        '/api/invoices/42',
        responseBody: '{"reference":"INV-1","total":1,"lines":[{"quantity":1,"child":{"quantity":"two"}}]}',
    ));

    $violation = $result->response->violations[0];

    expect($violation->where())->toBe('the response body at /lines/0/child/quantity')
        ->and($violation->schemaPointer)->toBe('/components/schemas/Line/properties/quantity');
});

it('falls back to the nearest provenance when the failing node records none', function (): void {
    $result = checkContract(contractExchange(
        'GET',
        '/api/invoices/42',
        responseBody: '{"total":1}',
    ));

    // `required` fails on the Invoice schema itself, which is the node carrying the trail.
    expect($result->response->violations[0]->provenance->lines())
        ->toBe(['integration:eloquent (integration) — app/Models/Invoice.php:22 in App\Models\Invoice']);
});

it('says nothing about provenance when the artifact is a plain OpenAPI export', function (): void {
    $result = checkContract(
        contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"INV-1","total":"x"}'),
        static fn (array $document): array => stripDocuccinoRecursive($document),
    );

    expect($result->response->violations[0]->provenance->isEmpty())->toBeTrue();
});

it('holds a response to the status the contract documents', function (int $status, string $body, ?string $expected): void {
    $result = checkContract(contractExchange('DELETE', '/api/invoices/42', status: $status, responseBody: $body, responseContentType: null));

    $messages = array_map(static fn ($v): string => $v->message, $result->response->violations);

    expect($messages === [] ? null : $messages[0])->toBe($expected);
})->with([
    'the documented empty response' => [204, '', null],
    'a status nothing documents' => [500, '', 'responded 500, which the contract does not document (it documents 204)'],
    'a body where none is documented' => [204, '{"a":1}', 'documents no body for 204, but the response returned 7 bytes'],
]);

it('reads a status range and a default response', function (): void {
    $range = checkContract(contractExchange(
        'POST',
        '/api/invoices',
        status: 422,
        responseBody: '{"detail":"nope"}',
        responseContentType: 'application/problem+json',
        requestBody: '{"reference":"INV-1"}',
    ));

    $default = checkContract(contractExchange(
        'GET',
        '/api/invoices/42',
        status: 503,
        responseBody: '{"detail":"down"}',
        responseContentType: 'application/problem+json',
    ));

    expect($range->response->ok())->toBeTrue()
        ->and($default->response->ok())->toBeTrue();
});

it('rejects a media type the contract does not document for that status', function (): void {
    $result = checkContract(contractExchange(
        'GET',
        '/api/invoices/42',
        responseBody: 'a,b',
        responseContentType: 'text/csv',
    ));

    expect($result->response->violations[0]->message)
        ->toBe('returned text/csv, which the contract does not document for 200 (it documents application/json)');
});

it('passes with a note where JSON Schema cannot check the body', function (): void {
    $result = checkContract(contractExchange(
        'GET',
        '/api/exports',
        responseBody: "id,total\n1,2",
        responseContentType: 'text/csv',
    ));

    expect($result->response->ok())->toBeTrue()
        ->and($result->notes())->toContain('the response body is text/csv, which JSON Schema cannot check');
});

it('passes with a note where the media type documents no schema', function (): void {
    $result = checkContract(
        contractExchange('GET', '/api/invoices/42', responseBody: '{"anything":true}'),
        static function (array $document): array {
            unset($document['paths']['/api/invoices/{invoice}']['get']['responses']['200']['content']['application/json']['schema']);

            return $document;
        },
    );

    expect($result->response->ok())->toBeTrue()
        ->and($result->notes())->toContain('the contract documents no schema for the response body (application/json)');
});

it('reports an empty body and a malformed one as the different problems they are', function (string $body, string $message): void {
    $result = checkContract(contractExchange('GET', '/api/invoices/42', responseBody: $body));

    expect($result->response->violations[0]->message)->toBe($message);
})->with([
    'empty' => ['', 'the response body is empty, but the contract documents a application/json body'],
    'not JSON' => ['{oops', 'the response body is not valid JSON: Syntax error'],
]);

it('checks query, header and path parameters against their schemas', function (array $query, array $headers, string $path, array $expected): void {
    $result = (new ContractChecker(contractIndex()))->check(
        contractExchange('GET', $path, query: $query, headers: $headers, responseBody: '[]'),
    );

    expect(array_map(static fn ($v): string => $v->where().': '.$v->message, $result->request->violations))->toBe($expected);
})->with([
    'everything in order' => [['page' => '2', 'sort' => 'total'], ['X-Tenant' => ['acme']], '/api/invoices', []],
    'a numeric string reads as the integer it is' => [['page' => '10'], ['X-Tenant' => ['acme']], '/api/invoices', []],
    'a string that is not the documented type stays a string' => [
        ['page' => 'first'], ['X-Tenant' => ['acme']], '/api/invoices',
        ['?page: The data (string) must match the type: integer'],
    ],
    'a constraint on a coerced value still applies' => [
        ['page' => '0'], ['X-Tenant' => ['acme']], '/api/invoices',
        ['?page: Number must be greater than or equal to 1'],
    ],
    'a comma list becomes the array it stands for' => [['sort' => 'total,-total'], ['X-Tenant' => ['acme']], '/api/invoices', []],
    'a value outside the documented enum' => [
        ['sort' => 'total,name'], ['X-Tenant' => ['acme']], '/api/invoices',
        ['?sort at /1: The data should match one item from enum'],
    ],
    'a required header nobody sent' => [
        [], [], '/api/invoices',
        ['header X-Tenant: is documented as required, but the request did not send it'],
    ],
    'the header name is matched case-insensitively' => [[], ['x-tenant' => ['acme']], '/api/invoices', []],
]);

it('checks every value a request sent under one header name, not just the first', function (array $sent, array $expected): void {
    // A request is a message and a message may send one name twice — `Accept`, `Cookie`, an
    // `X-Forwarded-For` a proxy appended to. The contract's claim about the header is a claim about
    // each value, exactly as it is on the response half, so one good value cannot cover a bad one.
    $result = checkContract(
        contractExchange('GET', '/api/invoices', headers: $sent === [] ? [] : ['X-Tenant' => $sent], responseBody: '[]'),
        static function (array $document): array {
            $tenant = $document['paths']['/api/invoices']['get']['parameters'][2];
            expect($tenant['name'])->toBe('X-Tenant');

            // `type: string` passes anything a header can carry, so the fixture's own schema could not
            // tell a checked value from an unread one.
            $document['paths']['/api/invoices']['get']['parameters'][2]['schema'] = [
                'type' => 'string',
                'enum' => ['acme', 'globex'],
            ];

            return $document;
        },
    );

    expect(array_map(static fn ($v): string => $v->where().': '.$v->message, $result->request->violations))->toBe($expected);
})->with([
    'sent once' => [['acme'], []],
    'sent twice, both documented' => [['acme', 'globex'], []],
    // The one the old model could not see. A first value that passes is not the contract being met.
    'sent twice, the second outside the documented set' => [
        ['acme', 'nope'],
        ['header X-Tenant (value 2): The data should match one item from enum'],
    ],
    'sent twice, the first outside it' => [
        ['nope', 'acme'],
        ['header X-Tenant (value 1): The data should match one item from enum'],
    ],
    'sent twice, neither documented' => [
        ['nope', 'worse'],
        [
            'header X-Tenant (value 1): The data should match one item from enum',
            'header X-Tenant (value 2): The data should match one item from enum',
        ],
    ],
    // One value still reads as the parameter itself: `(value 1)` of one is noise.
    'sent once, outside it' => [['nope'], ['header X-Tenant: The data should match one item from enum']],
    'not sent at all' => [[], ['header X-Tenant: is documented as required, but the request did not send it']],
]);

it('says out loud that a parameter with no schema member at all was not checked', function (): void {
    // The sibling of the not-schema-shaped case below: nothing was written, rather than something no
    // validator can take. Both pass, and both owe the reader a note saying so.
    $result = checkContract(
        contractExchange('GET', '/api/invoices', query: ['page' => 'zzz'], headers: ['X-Tenant' => ['acme']], responseBody: '[]'),
        static function (array $document): array {
            unset($document['paths']['/api/invoices']['get']['parameters'][0]['schema']);

            return $document;
        },
    );

    expect($result->request->ok())->toBeTrue()
        ->and($result->notes())->toContain('the contract documents no schema for ?page');
});

/**
 * The third answer, which a nullable schema folded into the second: something IS written where the
 * check looks and no reader can take it. "Nothing was written" and "nobody could read what was" are
 * different facts about the document — the distinction `requestBody` already draws
 * ({@see Refs::malformed()}) — and telling a reader their parameter documents no schema when it
 * documents one they mistyped sends them to the wrong place.
 */
it('says out loud that a parameter documented with something no reader can take was not checked', function (mixed $written, string $member): void {
    $result = checkContract(
        contractExchange('GET', '/api/invoices', query: ['page' => 'zzz'], headers: ['X-Tenant' => ['acme']], responseBody: '[]'),
        static function (array $document) use ($written, $member): array {
            unset($document['paths']['/api/invoices']['get']['parameters'][0]['schema']);
            $document['paths']['/api/invoices']['get']['parameters'][0][$member] = $written;

            return $document;
        },
    );

    expect($result->request->ok())->toBeTrue()
        ->and($result->notes())->toContain('?page is documented with a declaration this check cannot read')
        ->and($result->notes())->not->toContain('the contract documents no schema for ?page');
})->with([
    'a type name where a schema belongs' => ['integer', 'schema'],
    'a number where a schema belongs' => [42, 'schema'],
    'an explicit null' => [null, 'schema'],
    'a content member that is not a map of media types' => ['application/json', 'content'],
]);

it('names the source of a parameter that disagrees with its schema', function (): void {
    $result = (new ContractChecker(contractIndex()))->check(
        contractExchange('GET', '/api/invoices', query: ['page' => 'first'], headers: ['X-Tenant' => ['a']], responseBody: '[]'),
    );

    expect($result->request->violations[0]->provenance->lines())
        ->toBe(['integration:query-builder (integration) — app/Queries/InvoiceQuery.php:18 in App\Queries\InvoiceQuery::paginate']);
});

it('never calls a missing path parameter a violation — an unbound template did not match', function (): void {
    $checker = new ContractChecker(contractIndex());
    $operation = contractIndex()->match('GET', '/api/invoices/42');

    // A path the template cannot bind: nothing to read the parameter from, and nothing to report.
    expect($checker->request($operation, contractExchange('GET', '/api/other', responseBody: ''))->ok())->toBeTrue();
});

it('checks the request body against the documented schema', function (string $body, ?string $contentType, array $expected): void {
    $result = (new ContractChecker(contractIndex()))->check(
        contractExchange('POST', '/api/invoices', status: 201, responseBody: '{"reference":"INV-1","total":1}', requestBody: $body, requestContentType: $contentType),
    );

    expect(array_map(static fn ($v): string => $v->where().': '.$v->message, $result->request->violations))->toBe($expected);
})->with([
    'a valid body' => ['{"reference":"INV-1"}', 'application/json', []],
    'a member that breaks a constraint' => [
        '{"reference":"IN"}', 'application/json',
        ['the request body at /reference: Minimum string length is 3, found 2'],
    ],
    'a required body nobody sent' => ['', 'application/json', ['the request: sent no request body, which the contract documents as required']],
    'a media type the contract does not document' => [
        'reference=INV-1', 'application/x-www-form-urlencoded',
        ['the request: sent application/x-www-form-urlencoded, which the contract does not document as a request body (it documents application/json)'],
    ],
]);

it('says who wrote a request body schema that the request failed', function (): void {
    $result = (new ContractChecker(contractIndex()))->check(
        contractExchange('POST', '/api/invoices', status: 201, responseBody: '{"reference":"INV-1","total":1}', requestBody: '{"reference":"IN"}'),
    );

    expect($result->request->violations[0]->provenance->lines())
        ->toBe(['integration:form-request (integration) — app/Http/Requests/StoreInvoice.php:14 in App\Http\Requests\StoreInvoice::rules']);
});

it('leaves a request body alone when the operation documents none', function (): void {
    $result = (new ContractChecker(contractIndex()))->check(
        contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"a","total":1}', requestBody: '{"anything":true}'),
    );

    expect($result->request->ok())->toBeTrue();
});

it('checks only the half it was asked for', function (): void {
    $exchange = contractExchange('GET', '/api/invoices', responseBody: '[]');
    $checker = new ContractChecker(contractIndex());

    expect($checker->check($exchange, true, false)->response)->toBeNull()
        ->and($checker->check($exchange, false, true)->request)->toBeNull();
});

it('names the documented statuses in status order, and names only what a status could match', function (): void {
    // One grammar: what the message lists is what responseKeyFor() would resolve to, so a reader is never
    // pointed at a `responses` member the checker itself would skip. `4xx` and `twohundred` are the case
    // that matters — both are perfectly good response OBJECTS, so no is_array() guard drops them, and no
    // status resolves to either.
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => ['responses' => [
        '5XX' => ['description' => 'x'],
        '404' => ['description' => 'x'],
        '200' => ['description' => 'x'],
        '4xx' => ['description' => 'x'],
        'twohundred' => ['description' => 'x'],
        'broken' => 'not a response object',
    ]]]]]);

    $result = (new ContractChecker($index))->check(contractExchange('GET', '/a', status: 302));

    expect($result->response->violations[0]->message)
        ->toBe('responded 302, which the contract does not document (it documents 200, 404, 5XX)')
        ->and($index->operations()[0]->unreachableResponseKeys())->toBe(['4xx', 'twohundred']);
});

it('reads an operation whose responses member is not a map', function (): void {
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => ['responses' => 'nope']]]]);
    $result = (new ContractChecker($index))->check(contractExchange('GET', '/a'));

    expect($result->response->violations[0]->message)
        ->toBe('responded 200, which the contract does not document (it documents none)');
});

it('checks the documented response headers against the ones the response sent', function (array $sent, array $expected): void {
    $result = checkContract(contractExchange(
        'POST',
        '/api/invoices',
        status: 201,
        responseBody: '{"reference":"INV-1","total":1}',
        requestBody: '{"reference":"INV-1"}',
        responseHeaders: $sent,
    ));

    expect(array_map(static fn ($v): string => $v->where().': '.$v->message, $result->response->violations))->toBe($expected);
})->with([
    'every documented header, or none but the required one' => [
        ['Location' => ['/api/invoices/42'], 'X-RateLimit-Remaining' => ['4'], 'X-Legacy' => ['true']],
        [],
    ],
    'the required one alone' => [['Location' => ['/api/invoices/42']], []],
    'a required header the response never sent' => [
        ['X-RateLimit-Remaining' => ['4']],
        ['the response header Location: is documented as required, but the response did not send it'],
    ],
    'an optional header the response never sent is nobody’s violation' => [
        ['Location' => ['/api/invoices/42']],
        [],
    ],
    'a value that is not the documented type' => [
        ['Location' => ['/api/invoices/42'], 'X-RateLimit-Remaining' => ['plenty']],
        ['the response header X-RateLimit-Remaining: The data (string) must match the type: integer'],
    ],
    'a numeric string read as the integer it stands for, and held to the constraint' => [
        ['Location' => ['/api/invoices/42'], 'X-RateLimit-Remaining' => ['-1']],
        ['the response header X-RateLimit-Remaining: Number must be greater than or equal to 0'],
    ],
    'the header name is matched case-insensitively' => [['location' => ['/api/invoices/42']], []],
    'a $ref into components/headers resolves' => [
        ['Location' => ['/api/invoices/42'], 'X-Legacy' => ['maybe']],
        ['the response header X-Legacy: The data (string) must match the type: boolean'],
    ],
]);

/*
 * OpenAPI: "If a response header is defined with the name Content-Type, it SHALL be ignored." The
 * fixture documents that header as an integer precisely so a check that read it would have to fail.
 */
it('ignores a Content-Type entry in the headers map, as the specification requires', function (): void {
    $result = checkContract(contractExchange(
        'POST',
        '/api/invoices',
        status: 201,
        responseBody: '{"reference":"INV-1","total":1}',
        requestBody: '{"reference":"INV-1"}',
        responseHeaders: ['Location' => ['/api/invoices/42'], 'Content-Type' => ['application/json']],
    ));

    expect($result->response->ok())->toBeTrue()
        ->and($result->notes())->toBe([]);
});

it('holds every value of a header the response sent more than once', function (): void {
    $result = checkContract(contractExchange(
        'POST',
        '/api/invoices',
        status: 201,
        responseBody: '{"reference":"INV-1","total":1}',
        requestBody: '{"reference":"INV-1"}',
        responseHeaders: ['Location' => ['/api/invoices/42'], 'X-Chunk' => ['1', 'two', '3']],
    ));

    expect(array_map(static fn ($v): string => $v->where().': '.$v->message, $result->response->violations))
        ->toBe(['the response header X-Chunk (value 2): The data (string) must match the type: integer']);
});

it('names the declaration and the producer behind a required header nobody sent', function (): void {
    $missing = checkContract(contractExchange(
        'POST',
        '/api/invoices',
        status: 201,
        responseBody: '{"reference":"INV-1","total":1}',
        requestBody: '{"reference":"INV-1"}',
    ))->response->violations[0];

    expect($missing->schemaPointer)->toBe('/paths/~1api~1invoices/post/responses/201/headers/Location')
        ->and($missing->provenance->lines())->toBe([
            'integration:redirect (integration) — app/Http/Controllers/InvoiceController.php:44 in App\Http\Controllers\InvoiceController::store',
        ]);
});

it('points a header value violation at the header’s own schema', function (): void {
    $violation = checkContract(contractExchange(
        'POST',
        '/api/invoices',
        status: 201,
        responseBody: '{"reference":"INV-1","total":1}',
        requestBody: '{"reference":"INV-1"}',
        responseHeaders: ['Location' => ['/api/invoices/42'], 'X-RateLimit-Remaining' => ['plenty']],
    ))->response->violations[0];

    expect($violation->schemaPointer)
        ->toBe('/paths/~1api~1invoices/post/responses/201/headers/X-RateLimit-Remaining/schema');
});

it('passes with a note where a documented header cannot be checked', function (array $sent, string $note): void {
    $result = checkContract(
        contractExchange(
            'POST',
            '/api/invoices',
            status: 201,
            responseBody: '{"reference":"INV-1","total":1}',
            requestBody: '{"reference":"INV-1"}',
            responseHeaders: ['Location' => ['/api/invoices/42']] + $sent,
        ),
        // A header with neither `schema` nor `content` is not a document OAS would accept — so it is
        // written here rather than into the fixture, which answers to the OpenAPI meta-schema.
        static function (array $document): array {
            $document['paths']['/api/invoices']['post']['responses']['201']['headers']['X-Trace'] = [
                'description' => 'Whatever the tracer emitted.',
            ];

            return $document;
        },
    );

    expect($result->response->ok())->toBeTrue()
        ->and($result->notes())->toContain($note);
})->with([
    'a header object with no schema' => [
        ['X-Trace' => ['abc123']],
        'the contract documents no schema for the response header X-Trace',
    ],
    'a content-typed header' => [
        ['X-Signature' => ['ey.J.x']],
        'the response header X-Signature is documented as a content object, which the check does not decode',
    ],
]);

it('reads every uncheckable header rather than only the first', function (): void {
    $result = checkContract(
        contractExchange(
            'POST',
            '/api/invoices',
            status: 201,
            responseBody: '{"reference":"INV-1","total":1}',
            requestBody: '{"reference":"INV-1"}',
            responseHeaders: ['Location' => ['/a'], 'X-Trace' => ['abc'], 'X-Signature' => ['ey']],
        ),
        static function (array $document): array {
            $document['paths']['/api/invoices']['post']['responses']['201']['headers']['X-Trace'] = [];

            return $document;
        },
    );

    // One note per finding, in the order the document lists the headers.
    expect($result->notes())->toBe([
        'the response header X-Signature is documented as a content object, which the check does not decode; '.
        'the contract documents no schema for the response header X-Trace',
    ]);
});

it('reports a header and a body that both disagree, rather than stopping at the first', function (): void {
    $result = checkContract(contractExchange(
        'POST',
        '/api/invoices',
        status: 201,
        responseBody: '{"reference":"INV-1","total":"lots"}',
        requestBody: '{"reference":"INV-1"}',
        responseHeaders: ['Location' => ['/a'], 'X-RateLimit-Remaining' => ['plenty']],
    ));

    expect(array_map(static fn ($v): string => $v->where(), $result->response->violations))
        ->toBe(['the response header X-RateLimit-Remaining', 'the response body at /total']);
});

it('leaves a response the contract documents no headers for alone', function (): void {
    $result = checkContract(contractExchange(
        'GET',
        '/api/invoices/42',
        responseBody: '{"reference":"INV-1","total":1}',
        responseHeaders: ['X-Whatever' => ['anything']],
    ));

    expect($result->response->ok())->toBeTrue()
        ->and($result->notes())->toBe([]);
});

it('degrades over a headers map that is not one, and over an entry that is not an object', function (mixed $headers, bool $ok): void {
    $result = checkContract(
        contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"a","total":1}', responseHeaders: ['X-Odd' => ['1']]),
        static function (array $document) use ($headers): array {
            $document['paths']['/api/invoices/{invoice}']['get']['responses']['200']['headers'] = $headers;

            return $document;
        },
    );

    expect($result->response->ok())->toBe($ok);
})->with([
    'a headers member that is a string' => ['nope', true],
    'an entry that is a string' => [['X-Odd' => 'nope'], true],
]);

it('says out loud that a parameter documented without a schema was not checked', function (): void {
    $result = checkContract(
        contractExchange('GET', '/api/invoices', query: ['page' => '2'], headers: ['X-Tenant' => ['acme']], responseBody: '[]'),
        static function (array $document): array {
            unset($document['paths']['/api/invoices']['get']['parameters'][0]['schema']);
            $document['paths']['/api/invoices']['get']['parameters'][0]['content'] = [
                'application/json' => ['schema' => ['type' => 'integer']],
            ];

            return $document;
        },
    );

    expect($result->request->ok())->toBeTrue()
        ->and($result->notes())->toContain('?page is documented as a content object, which the check does not decode');
});

it('fails a header whose declaration is a reference the contract does not define', function (array $entry, string $pointer): void {
    // The whole check runs off the node the `$ref` lands on. Where it lands nowhere there is no
    // `required` and no `schema` to read, so an absent header would otherwise be judged against
    // nothing and reported as a pass — a one-character typo turning the contract into a no-op.
    $result = checkContract(
        contractExchange(
            'POST',
            '/api/invoices',
            status: 201,
            responseBody: '{"reference":"INV-1","total":1}',
            requestBody: '{"reference":"INV-1"}',
            responseHeaders: ['Location' => ['/api/invoices/42']],
        ),
        static function (array $document) use ($entry): array {
            $document['components']['headers']['RateLimit'] = ['required' => true, 'schema' => ['type' => 'integer']];
            $document['components']['headers']['Loop'] = ['$ref' => '#/components/headers/Knot'];
            $document['components']['headers']['Knot'] = ['$ref' => '#/components/headers/Loop'];
            $document['paths']['/api/invoices']['post']['responses']['201']['headers']['RateLimit'] = $entry;

            return $document;
        },
    );

    $violation = collect($result->response->violations)
        ->first(static fn ($v): bool => $v->location === 'the response header RateLimit');

    expect($result->response->ok())->toBeFalse()
        ->and($violation)->not->toBeNull()
        ->and($violation->message)->toContain('which the contract does not define')
        ->and($violation->schemaPointer)->toBe($pointer);
})->with([
    // The pointer names the last node the chain reached, which for a name nothing defines is the
    // declaration itself and for a loop is where the loop closes — in both cases, where to go and look.
    'a reference at a name nothing defines' => [
        ['$ref' => '#/components/headers/RateLimitt'],
        '/paths/~1api~1invoices/post/responses/201/headers/RateLimit',
    ],
    'a reference chain that never lands' => [
        ['$ref' => '#/components/headers/Loop'],
        '/components/headers/Knot',
    ],
]);

it('fails a request parameter documented behind a reference the contract does not define', function (): void {
    $result = checkContract(
        contractExchange('GET', '/api/invoices', headers: ['X-Tenant' => ['acme']], responseBody: '[]'),
        static function (array $document): array {
            $document['paths']['/api/invoices']['get']['parameters'][] = ['$ref' => '#/components/parameters/Cursor'];

            return $document;
        },
    );

    expect($result->request->ok())->toBeFalse()
        ->and($result->request->violations[0]->message)
        ->toContain('#/components/parameters/Cursor')
        ->and($result->request->violations[0]->message)->toContain('which the contract does not define');
});

it('says out loud that a request body written in a shape it cannot read was not checked', function (mixed $written): void {
    // The third way this check answered "nothing here" for two different reasons. An operation with no
    // `requestBody` promises nothing about one, and passing in silence is right; a `requestBody` that
    // is not an object is a promise NOBODY LOOKED AT, and it read as the first — the payload below
    // matches no documented body and was passing.
    $result = checkContract(
        contractExchange(
            'POST',
            '/api/invoices',
            status: 201,
            responseBody: '{"reference":"INV-1","total":1}',
            requestBody: '{"nothing":"the documented body would accept"}',
            responseHeaders: ['Location' => ['/api/invoices/42']],
        ),
        static function (array $document) use ($written): array {
            $document['paths']['/api/invoices']['post']['requestBody'] = $written;

            return $document;
        },
    );

    expect($result->request->ok())->toBeTrue()
        ->and($result->notes())->toContain('the contract documents a request body this check cannot read');
})->with([
    'a string where an object belongs' => ['a body, honest'],
    'a number' => [42],
    'an explicit null' => [null],
]);

it('still passes an operation that documents no request body in silence', function (): void {
    // The other side of the note above: nothing was written, so there is no promise to have skipped,
    // and a note here would fire on every GET in every suite.
    $result = checkContract(contractExchange('GET', '/api/invoices', headers: ['X-Tenant' => ['acme']], responseBody: '[]'));

    expect($result->request->ok())->toBeTrue()
        ->and($result->notes())->toBe([]);
});

it('fails a request body documented behind a reference the contract does not define', function (): void {
    $result = checkContract(
        contractExchange(
            'POST',
            '/api/invoices',
            status: 201,
            responseBody: '{"reference":"INV-1","total":1}',
            requestBody: '{"reference":"INV-1"}',
            responseHeaders: ['Location' => ['/api/invoices/42']],
        ),
        static function (array $document): array {
            $document['paths']['/api/invoices']['post']['requestBody'] = ['$ref' => '#/components/requestBodies/Draft'];

            return $document;
        },
    );

    expect($result->request->ok())->toBeFalse()
        ->and($result->request->violations[0]->message)->toContain('#/components/requestBodies/Draft');
});

it('fails a response documented behind a reference the contract does not define', function (): void {
    $result = checkContract(
        contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"INV-1","total":1}'),
        static function (array $document): array {
            $document['paths']['/api/invoices/{invoice}']['get']['responses']['200'] = ['$ref' => '#/components/responses/Invoice'];

            return $document;
        },
    );

    expect($result->response->ok())->toBeFalse()
        ->and($result->response->violations[0]->message)->toContain('#/components/responses/Invoice');
});

it('keeps checking against a schema written as an empty object', function (): void {
    // Associative decoding spells `{}` as `[]`, and the empty schema accepts everything — so this
    // passes with nothing to say, which is the truth rather than a silence.
    $result = checkContract(
        contractExchange(
            'GET',
            '/api/invoices',
            query: ['page' => 'zzz'],
            headers: ['X-Tenant' => ['acme']],
            responseBody: '[]',
        ),
        static function (array $document): array {
            $document['paths']['/api/invoices']['get']['parameters'][0]['schema'] = [];

            return $document;
        },
    );

    expect($result->request->ok())->toBeTrue()
        ->and($result->notes())->toBe([]);
});

it('fails rather than crashing where a schema points at a definition nothing resolves', function (string $ref): void {
    // The validator parses each schema as it reaches it and THROWS over an unresolvable reference.
    // `#/definitions/…` is the everyday one: it is what an artifact converted from Swagger 2.0 or
    // draft-07 carries, and every assertion against such a document died with a stack trace.
    $result = checkContract(
        contractExchange(
            'GET',
            '/api/invoices',
            query: ['page' => '2'],
            headers: ['X-Tenant' => ['acme']],
            responseBody: '[]',
        ),
        static function (array $document) use ($ref): array {
            $document['paths']['/api/invoices']['get']['parameters'][0]['schema'] = ['$ref' => $ref];

            return $document;
        },
    );

    expect($result->request->ok())->toBeFalse()
        ->and($result->request->violations[0]->location)->toBe('?page')
        ->and($result->request->violations[0]->message)->toContain('could not be checked against the contract')
        ->and($result->request->violations[0]->schemaPointer)
        ->toBe('/paths/~1api~1invoices/get/parameters/0/schema');
})->with([
    'a component nothing defines' => ['#/components/schemas/Missing'],
    'a draft-07 definitions pointer' => ['#/definitions/Thing'],
]);

it('fails rather than crashing where a BODY schema points at a definition nothing resolves', function (): void {
    $result = checkContract(
        contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"INV-1","total":1}'),
        static function (array $document): array {
            $document['paths']['/api/invoices/{invoice}']['get']['responses']['200']['content']['application/json']['schema']
                = ['$ref' => '#/definitions/Invoice'];

            return $document;
        },
    );

    expect($result->response->ok())->toBeFalse()
        ->and($result->response->violations[0]->location)->toBe('the response body')
        ->and($result->response->violations[0]->message)->toContain('could not be checked against the contract');
});

it('orders response header violations by name rather than by the order the document wrote them', function (array $headers, array $expected): void {
    // Two documents saying exactly the same thing must fail identically. An order read off the
    // document's key order makes the failure a function of how the file happened to be written.
    $result = checkContract(
        contractExchange(
            'GET',
            '/api/invoices/42',
            responseBody: '{"reference":"INV-1","total":1}',
            responseHeaders: ['X-A' => ['no'], 'X-B' => ['no'], 'X-C' => ['no']],
        ),
        static function (array $document) use ($headers): array {
            $document['paths']['/api/invoices/{invoice}']['get']['responses']['200']['headers'] = $headers;

            return $document;
        },
    );

    expect(array_map(static fn ($v): string => $v->location, $result->response->violations))->toBe($expected);
})->with(function () {
    $integer = ['schema' => ['type' => 'integer']];
    $expected = ['the response header X-A', 'the response header X-B', 'the response header X-C'];

    return [
        'written in order' => [['X-A' => $integer, 'X-B' => $integer, 'X-C' => $integer], $expected],
        'written out of order' => [['X-C' => $integer, 'X-A' => $integer, 'X-B' => $integer], $expected],
    ];
});

it('documents every fixture header with a schema or a content, as OAS requires', function (): void {
    // The schema-less header cases above are written as MUTATIONS rather than into the fixture, on
    // the grounds that the fixture is a document OAS accepts. Nothing held the fixture to that, so
    // this does — and it counts what it found, so a walk that stopped seeing header objects fails
    // rather than passing forever on nothing.
    $document = loadFixture('contract.uir.json');

    $found = [];
    foreach ($document['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            foreach ($operation['responses'] ?? [] as $status => $response) {
                foreach ($response['headers'] ?? [] as $name => $header) {
                    $found[$path.' '.$method.' '.$status.' '.$name] = array_key_exists('schema', $header)
                        || array_key_exists('content', $header)
                        || array_key_exists('$ref', $header);
                }
            }
        }
    }

    foreach ($document['components']['headers'] as $name => $header) {
        $found['components/headers/'.$name] = array_key_exists('schema', $header)
            || array_key_exists('content', $header);
    }

    expect(count($found))->toBeGreaterThanOrEqual(6)
        ->and(array_keys(array_filter($found, static fn (bool $ok): bool => ! $ok)))->toBe([]);
});

it('checks a parameter whose type is behind a reference or a composition against that type', function (array $schema): void {
    // The coercion that reads `?page=2` back as an integer has to unwrap whatever the document wrote
    // the type behind, because the validator it feeds does. A reader that saw only a literal `type`
    // handed the wire string to a schema that says `integer`, and every such request failed.
    $result = checkContract(
        contractExchange('GET', '/api/invoices', query: ['page' => '2'], headers: ['X-Tenant' => ['acme']], responseBody: '[]'),
        static function (array $document) use ($schema): array {
            $document['components']['schemas']['PerPage'] = ['type' => 'integer', 'minimum' => 1];
            $document['paths']['/api/invoices']['get']['parameters'][0]['schema'] = $schema;

            return $document;
        },
    );

    expect($result->request->violations)->toBe([])
        ->and($result->request->ok())->toBeTrue();
})->with(contractSchemaSpellings());

it('still fails a parameter that is not the documented type, whatever the type is written behind', function (array $schema): void {
    // Negative half of the same table: widening the reader must not widen what passes. `?page=abc`
    // still has to read as the type problem it is rather than becoming the integer zero.
    $result = checkContract(
        contractExchange('GET', '/api/invoices', query: ['page' => 'abc'], headers: ['X-Tenant' => ['acme']], responseBody: '[]'),
        static function (array $document) use ($schema): array {
            $document['components']['schemas']['PerPage'] = ['type' => 'integer', 'minimum' => 1];
            $document['paths']['/api/invoices']['get']['parameters'][0]['schema'] = $schema;

            return $document;
        },
    );

    // A union reports one leaf per branch it failed, so the assertion is that the type problem is
    // among them — not that it is the only thing the validator had to say.
    expect(array_map(static fn ($v): string => $v->where().': '.$v->message, $result->request->violations))
        ->toContain('?page: The data (string) must match the type: integer');
})->with(contractSchemaSpellings());

it('checks a response header whose type is behind a reference or a composition against that type', function (array $schema): void {
    // Both halves reach coercion through the one shared parameter check, so a fix to the request half
    // alone would have left every non-string response header failing.
    $result = checkContract(
        contractExchange(
            'POST',
            '/api/invoices',
            status: 201,
            responseBody: '{"reference":"INV-1","total":1}',
            responseHeaders: ['Location' => ['/api/invoices/42'], 'X-RateLimit-Remaining' => ['4']],
        ),
        static function (array $document) use ($schema): array {
            $document['components']['schemas']['PerPage'] = ['type' => 'integer', 'minimum' => 0];
            $document['paths']['/api/invoices']['post']['responses']['201']['headers']['X-RateLimit-Remaining']['schema'] = $schema;

            return $document;
        },
    );

    expect($result->response->violations)->toBe([]);
})->with(contractSchemaSpellings());
