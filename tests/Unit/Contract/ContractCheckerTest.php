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
    'everything in order' => [['page' => '2', 'sort' => 'total'], ['X-Tenant' => 'acme'], '/api/invoices', []],
    'a numeric string reads as the integer it is' => [['page' => '10'], ['X-Tenant' => 'acme'], '/api/invoices', []],
    'a string that is not the documented type stays a string' => [
        ['page' => 'first'], ['X-Tenant' => 'acme'], '/api/invoices',
        ['?page: The data (string) must match the type: integer'],
    ],
    'a constraint on a coerced value still applies' => [
        ['page' => '0'], ['X-Tenant' => 'acme'], '/api/invoices',
        ['?page: Number must be greater than or equal to 1'],
    ],
    'a comma list becomes the array it stands for' => [['sort' => 'total,-total'], ['X-Tenant' => 'acme'], '/api/invoices', []],
    'a value outside the documented enum' => [
        ['sort' => 'total,name'], ['X-Tenant' => 'acme'], '/api/invoices',
        ['?sort at /1: The data should match one item from enum'],
    ],
    'a required header nobody sent' => [
        [], [], '/api/invoices',
        ['header X-Tenant: is documented as required, but the request did not send it'],
    ],
    'the header name is matched case-insensitively' => [[], ['x-tenant' => 'acme'], '/api/invoices', []],
]);

it('names the source of a parameter that disagrees with its schema', function (): void {
    $result = (new ContractChecker(contractIndex()))->check(
        contractExchange('GET', '/api/invoices', query: ['page' => 'first'], headers: ['X-Tenant' => 'a'], responseBody: '[]'),
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

it('reads an operation whose responses member is not a map', function (): void {
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => ['responses' => 'nope']]]]);
    $result = (new ContractChecker($index))->check(contractExchange('GET', '/a'));

    expect($result->response->violations[0]->message)
        ->toBe('responded 200, which the contract does not document (it documents none)');
});
