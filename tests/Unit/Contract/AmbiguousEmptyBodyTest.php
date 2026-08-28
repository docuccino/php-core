<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;

/*
 * `[]` where `{}` was meant, at the checking seam.
 *
 * A producer whose one array type is both JSON containers has to pick when it serialises, and it picks
 * the list — so a body it wrote as `[]` is not evidence anybody meant a list. The exchange says whether
 * its producer is one of those ({@see Exchange::$ambiguousEmptyRequestBody}); the DOCUMENT decides what
 * that permits, and it permits exactly one thing: reading the empty array as the empty object where the
 * schema at hand accepts an empty object. Everything else the document rejects goes on being rejected,
 * which is what the rest of this file pins.
 */

/**
 * The fixture with `POST /api/invoices` documenting `$schema` as its body.
 *
 * @param  array<string, mixed>  $schema
 */
function draftBodyOf(array $schema): ContractIndex
{
    return contractIndex(static function (array $document) use ($schema): array {
        $document['components']['schemas']['InvoiceDraft'] = $schema;

        return $document;
    });
}

/**
 * The request half's messages for a `POST /api/invoices` carrying `$body`, against a document whose
 * draft schema is `$schema`.
 *
 * @param  array<string, mixed>  $schema
 * @return list<string>
 */
function draftRequestMessages(array $schema, string $body, bool $ambiguous): array
{
    $result = (new ContractChecker(draftBodyOf($schema)))->check(contractExchange(
        'POST',
        '/api/invoices',
        status: 201,
        responseBody: '{"reference":"INV-1","total":1}',
        requestBody: $body,
        ambiguousEmptyRequestBody: $ambiguous,
    ));

    return array_map(static fn ($v): string => $v->where().': '.$v->message, $result->request->violations);
}

it('reads an ambiguous empty array as the empty object the contract accepts', function (): void {
    $optional = ['type' => 'object', 'properties' => ['reference' => ['type' => 'string']]];

    expect(draftRequestMessages($optional, '[]', ambiguous: true))->toBe([]);
});

it('holds a producer that CAN spell an empty object to the array it actually sent', function (): void {
    // The same document and the same bytes, from a PSR-7 pair or a HAR entry: there `[]` was a choice,
    // and a checker that read it as `{}` would be passing a body the contract does not document.
    $optional = ['type' => 'object', 'properties' => ['reference' => ['type' => 'string']]];

    expect(draftRequestMessages($optional, '[]', ambiguous: false))
        ->toBe(['the request body: The data (array) must match the type: object']);
});

it('refuses the empty object where the contract refuses it, ambiguous or not', function (): void {
    // The widening is OFFERED to the schema rather than assumed of it: this one requires `reference`,
    // so neither reading of `[]` satisfies it and the body goes on being reported as the array it is.
    $required = loadFixture('contract.uir.json')['components']['schemas']['InvoiceDraft'];

    expect($required)->toHaveKey('required')
        ->and(draftRequestMessages($required, '[]', ambiguous: true))
        ->toBe(['the request body: The data (array) must match the type: object']);
});

it('leaves a documented array body an array, so its own constraints still bite', function (array $schema, string $body, array $expected): void {
    /** @var array<string, mixed> $schema */
    expect(draftRequestMessages($schema, $body, ambiguous: true))->toBe($expected);
})->with([
    // The case the widening must never take: an endpoint whose body IS a list, tested with the empty
    // one. Reading it as `{}` would fail a request the contract documents as fine.
    'an empty list a list-bodied endpoint accepts' => [
        ['type' => 'array', 'items' => ['type' => 'string']],
        '[]',
        [],
    ],
    // And the case it must never rescue: the contract says at least one entry, and none were sent.
    'an empty list where the contract wants entries' => [
        ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
        '[]',
        ['the request body: Array should have at least 1 items, 0 found'],
    ],
    // Only the EMPTY array is ambiguous. A list with something in it said what it was.
    'a populated array against an object schema' => [
        ['type' => 'object', 'properties' => ['reference' => ['type' => 'string']]],
        '[{"reference":"INV-1"}]',
        ['the request body: The data (array) must match the type: object'],
    ],
]);

it('never lets the flag stand in for a body nobody sent', function (): void {
    // `''` is absent and `[]` is present-and-empty, and they take different branches: absence answers
    // to `required`, and this body is documented as required.
    expect(draftRequestMessages(['type' => 'object'], '', ambiguous: true))
        ->toBe(['the request: sent no request body, which the contract documents as required']);
});

it('says nothing about the response half, which is what a consumer really receives', function (): void {
    // A controller that returns `[]` for an empty object ships `[]` to every client, and a client
    // generated from an object schema breaks on it. The flag is the REQUEST half's alone.
    $result = (new ContractChecker(contractIndex()))->check(contractExchange(
        'GET',
        '/api/invoices/42',
        responseBody: '[]',
        requestBody: '',
        ambiguousEmptyRequestBody: true,
    ));

    expect($result->response->ok())->toBeFalse()
        ->and($result->response->violations[0]->message)->toContain('must match the type: object');
});

it('reads an ambiguous empty delivery the same way, and a definite one not at all', function (): void {
    $index = contractIndex(static function (array $document): array {
        $document['webhooks']['invoice.paid']['post']['requestBody']['content']['application/json']['schema']
            = ['type' => 'object', 'properties' => ['reference' => ['type' => 'string']]];

        return $document;
    });

    $webhook = $index->webhooksNamed('invoice.paid')[0];
    $checker = new ContractChecker($index);

    expect($checker->delivery($webhook, '[]', ambiguousEmptyPayload: true)->ok())->toBeTrue()
        ->and($checker->delivery($webhook, '[]')->ok())->toBeFalse();
});
