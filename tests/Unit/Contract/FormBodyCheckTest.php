<?php

declare(strict_types=1);

use Docuccino\Core\Contract\CheckResult;
use Docuccino\Core\Contract\Exchange;
use Docuccino\Core\Contract\Violation;

/*
 * A form request body — `application/x-www-form-urlencoded` or `multipart/form-data` — is one the
 * framework parsed, so it reaches a check as FIELDS rather than as bytes ({@see Exchange::$requestForm}).
 * Everything here is about the pair of facts that follows: those fields are checkable against the
 * ordinary object schema the document publishes for them, and a form body that arrives as bytes nobody
 * decoded is not checked and has to say so.
 */

/** @return list<string> */
function formFindings(CheckResult $result): array
{
    return array_map(
        static fn (Violation $violation): string => $violation->where().': '.$violation->message,
        $result->request->violations,
    );
}

it('checks a form request body, and says what it could not', function (Exchange $exchange, array $violations, array $notes): void {
    $result = checkFormContract($exchange);

    expect(formFindings($result))->toBe($violations)
        ->and($result->notes())->toBe($notes);
})->with([
    /*
     * The whole domain in one table: the three media types a body can be documented under, crossed with
     * what a request can arrive as, crossed with `required`. Two guards over two halves of this cover
     * their halves and nothing between them, so it is one table — and a member that owes no answer
     * carries a row saying so rather than being left out.
     */

    // multipart, required — the population `POST /api/tickets` stands in for on the Laravel side.
    'a multipart form that matches' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: [
            'title' => 'Receipt', 'quantity' => 2, 'attachment' => 'scan.pdf',
        ]),
        [], [],
    ],
    'a multipart form whose members are the wire strings a real client sends' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: [
            'title' => 'Receipt', 'quantity' => '2', 'attachment' => 'scan.pdf', 'tags' => ['a', 'b'],
        ]),
        [], [],
    ],
    'a member that is not the documented type, and could not be read as it' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: [
            'title' => 'Receipt', 'quantity' => 'abc', 'attachment' => 'scan.pdf',
        ]),
        ['the request body at /quantity: The data (string) must match the type: integer'], [],
    ],
    'a member that breaks a constraint' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: [
            'title' => 'ab', 'quantity' => 2, 'attachment' => 'scan.pdf',
        ]),
        ['the request body at /title: Minimum string length is 3, found 2'], [],
    ],
    'parts the contract documents as required and the request left out' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: [
            'title' => 'Receipt',
        ]),
        ['the request body: The required properties (quantity, attachment) are missing'], [],
    ],
    'a part the contract documents no property for' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: [
            'title' => 'Receipt', 'quantity' => 2, 'attachment' => 'scan.pdf', 'surprise' => 'x',
        ]),
        ['the request body: Additional object properties are not allowed: surprise'], [],
    ],

    // multipart, required, nothing sent — the three ways that goes.
    'a required form body nobody sent, under the documented type' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data'),
        ['the request: sent no request body, which the contract documents as required'], [],
    ],
    'a required form body nobody sent, under no type at all' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: null),
        // The row that says a member owes no answer: there is no declared type here to call
        // undocumented, and naming one would be a second sentence about a single mistake.
        ['the request: sent no request body, which the contract documents as required'], [],
    ],
    'a required form body nobody sent, under a type the contract does not document' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'application/json'),
        [
            'the request: sent no request body, which the contract documents as required',
            'the request: sent application/json, which the contract does not document as a request body (it documents multipart/form-data)',
        ],
        [],
    ],

    // The type a form body arrives under decides which entry describes it, exactly as for JSON.
    'a form body sent under a type the contract documents no entry for' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'application/x-www-form-urlencoded', requestForm: [
            'title' => 'Receipt', 'quantity' => 2, 'attachment' => 'scan.pdf',
        ]),
        ['the request: sent application/x-www-form-urlencoded, which the contract does not document as a request body (it documents multipart/form-data)'],
        [],
    ],
    'a form body that reached the check as bytes nothing decoded' => [
        contractExchange('POST', '/api/uploads', status: 201, requestBody: '--x--', requestContentType: 'multipart/form-data'),
        [],
        ['the request body is multipart/form-data, and reached the check as bytes rather than as the fields its schema describes'],
    ],

    // urlencoded, NOT required — the quiet half. A body the contract said might not come and did not
    // is the one honest silent pass here; a body that DID come is checked like any other.
    'an optional form body nobody sent' => [
        contractExchange('PUT', '/api/preferences', status: 204, responseContentType: null, requestContentType: null),
        [], [],
    ],
    'an optional form body stated as no fields at all' => [
        // An empty map and no map are the same request: a form body with no fields is zero bytes.
        contractExchange('PUT', '/api/preferences', status: 204, responseContentType: null, requestContentType: 'application/x-www-form-urlencoded', requestForm: []),
        [], [],
    ],
    'an optional form body that was sent, and matches' => [
        contractExchange('PUT', '/api/preferences', status: 204, responseContentType: null, requestContentType: 'application/x-www-form-urlencoded', requestForm: [
            'theme' => 'dark', 'notify' => 'true',
        ]),
        [], [],
    ],
    'an optional form body that was sent, and is outside the documented set' => [
        contractExchange('PUT', '/api/preferences', status: 204, responseContentType: null, requestContentType: 'application/x-www-form-urlencoded', requestForm: [
            'theme' => 'neon',
        ]),
        ['the request body at /theme: The data should match one item from enum'], [],
    ],

    // urlencoded, required — the member the two guards above left between them.
    'a urlencoded form the contract requires, and got' => [
        contractExchange('POST', '/api/subscriptions', status: 201, requestContentType: 'application/x-www-form-urlencoded', requestForm: [
            'theme' => 'dark',
        ]),
        [], [],
    ],
    'a urlencoded form the contract requires, and nobody sent' => [
        contractExchange('POST', '/api/subscriptions', status: 201, requestContentType: 'application/x-www-form-urlencoded'),
        ['the request: sent no request body, which the contract documents as required'], [],
    ],
    'a urlencoded form body that reached the check as bytes nothing decoded' => [
        contractExchange('PUT', '/api/preferences', status: 204, responseContentType: null, requestBody: 'theme=dark', requestContentType: 'application/x-www-form-urlencoded'),
        [],
        ['the request body is application/x-www-form-urlencoded, and reached the check as bytes rather than as the fields its schema describes'],
    ],

    // multipart, NOT required — the other diagonal of the same pair.
    'an optional multipart form that was sent, and matches' => [
        contractExchange('PUT', '/api/avatars', status: 204, responseContentType: null, requestContentType: 'multipart/form-data', requestForm: [
            'portrait' => 'face.png', 'caption' => 'me',
        ]),
        [], [],
    ],
    'an optional multipart form nobody sent' => [
        contractExchange('PUT', '/api/avatars', status: 204, responseContentType: null, requestContentType: null),
        [], [],
    ],
    'an optional multipart form that was sent, and is missing a part' => [
        contractExchange('PUT', '/api/avatars', status: 204, responseContentType: null, requestContentType: 'multipart/form-data', requestForm: [
            'caption' => 'me',
        ]),
        ['the request body: The required properties (portrait) are missing'], [],
    ],

    // Several documented media types, which is the only way a request that DECLARED nothing reaches no
    // entry at all — there is nothing to choose between one, and everything to choose between two.
    'a form sent under no declared type, where the contract documents two' => [
        contractExchange('POST', '/api/imports', status: 201, requestContentType: null, requestForm: ['theme' => 'dark']),
        ['the request: sent no content type, which the contract does not document as a request body (it documents application/json, multipart/form-data)'],
        [],
    ],
    'a required body nobody sent, under no type, where the contract documents two' => [
        // The one pairing left unsaid, on the road that reaches it by a different door: no entry matched,
        // and still no type to call undocumented.
        contractExchange('POST', '/api/imports', status: 201, requestContentType: null),
        ['the request: sent no request body, which the contract documents as required'], [],
    ],
    'a required body nobody sent, under an undocumented type, where the contract documents two' => [
        contractExchange('POST', '/api/imports', status: 201, requestContentType: 'text/csv'),
        [
            'the request: sent no request body, which the contract documents as required',
            'the request: sent text/csv, which the contract does not document as a request body (it documents application/json, multipart/form-data)',
        ],
        [],
    ],
    'a form sent under the multipart entry of two' => [
        contractExchange('POST', '/api/imports', status: 201, requestContentType: 'multipart/form-data', requestForm: ['theme' => 'dark']),
        [], [],
    ],

    // Nobody could say what this request sent, so nothing about it is read as a fact — not the empty
    // body, and not the type it declared.
    'a request whose body the adapter could not read' => [
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'application/json', requestBodyUnread: 'the fields were rewritten before anything read them'),
        [],
        ['the request body could not be read: the fields were rewritten before anything read them'],
    ],

    // JSON, required — the control, and the mirror of the mismatch above.
    'a JSON body, checked as it always was' => [
        contractExchange('POST', '/api/notes', status: 201, requestBody: '{"theme":"light"}', requestContentType: 'application/json'),
        [], [],
    ],
    'a JSON body the request sent as a form instead' => [
        contractExchange('POST', '/api/notes', status: 201, requestContentType: 'application/x-www-form-urlencoded', requestForm: ['theme' => 'light']),
        ['the request: sent application/x-www-form-urlencoded, which the contract does not document as a request body (it documents application/json)'],
        [],
    ],
    'a body sent under a type no one has heard of' => [
        contractExchange('POST', '/api/notes', status: 201, requestBody: 'theme=light', requestContentType: 'application/x-made-up'),
        ['the request: sent application/x-made-up, which the contract does not document as a request body (it documents application/json)'],
        [],
    ],
]);

it('says out loud that a form body documented with no schema was not checked', function (): void {
    $result = checkFormContract(
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: ['anything' => 'at all']),
        static function (array $document): array {
            unset($document['paths']['/api/uploads']['post']['requestBody']['content']['multipart/form-data']['schema']);

            return $document;
        },
    );

    expect($result->request->ok())->toBeTrue()
        ->and($result->notes())->toBe(['the contract documents no schema for the request body (multipart/form-data)']);
});

it('reads a form body against a media type documented with a boolean schema', function (bool $schema, array $violations): void {
    // `true` and `false` are schemas: one takes every value and the other takes none. Neither is "no
    // schema", which is the note beside them, so both are checked rather than passed over.
    $result = checkFormContract(
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: ['title' => 'Receipt']),
        static function (array $document) use ($schema): array {
            $document['paths']['/api/uploads']['post']['requestBody']['content']['multipart/form-data']['schema'] = $schema;

            return $document;
        },
    );

    expect(formFindings($result))->toBe($violations)
        ->and($result->notes())->toBe([]);
})->with([
    'a schema that admits anything' => [true, []],
    'a schema that admits nothing' => [false, ['the request body: Data not allowed']],
]);

/*
 * A form is not a query string, and the one place they part company is the comma. `?sort=a,-b` is the
 * list representation the generator documents; a form array is the repeated key `tags=a&tags=b`, which
 * the framework hands over as a list already. Splitting `tags=a,b` would take a body the server rejects
 * and pass it.
 */
it('reads a comma in a form member as the character it is', function (): void {
    $result = checkFormContract(contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: [
        'title' => 'Receipt', 'quantity' => 2, 'attachment' => 'scan.pdf', 'tags' => 'a,b',
    ]));

    expect(formFindings($result))->toBe(['the request body at /tags: The data (string) must match the type: array']);
});

it('checks a form body against a content key the author spelled in capitals', function (): void {
    // `select()` matches case-insensitively and hands back the document's own spelling, so everything
    // downstream has to read that spelling the same way — otherwise the entry is found and the body it
    // describes is passed over as bytes nobody decoded.
    $result = checkFormContract(
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: ['title' => 'ab']),
        static function (array $document): array {
            $body = &$document['paths']['/api/uploads']['post']['requestBody']['content'];
            $body = ['Multipart/Form-Data' => $body['multipart/form-data']];

            return $document;
        },
    );

    expect(formFindings($result))->toBe([
        'the request body: The required properties (quantity, attachment) are missing',
    ])->and($result->notes())->toBe([]);
});

it('names the producer that wrote the schema a form part disagrees with', function (): void {
    // The whole reason the assertions read UIR: a failing part points at the rules() that wrote it.
    $result = checkFormContract(contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: [
        'title' => 'ab', 'quantity' => 2, 'attachment' => 'scan.pdf',
    ]));

    expect($result->request->violations[0]->provenance->lines())
        ->toBe(['integration:form-request (integration) — app/Http/Requests/StoreUpload.php:21 in App\Http\Requests\StoreUpload::rules']);
});

it('fails a form body behind a reference the contract does not define, rather than passing it', function (): void {
    $result = checkFormContract(
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: ['title' => 'Receipt']),
        static function (array $document): array {
            $document['paths']['/api/uploads']['post']['requestBody']['content']['multipart/form-data']['schema'] = ['$ref' => '#/components/schemas/Nowhere'];

            return $document;
        },
    );

    expect(formFindings($result))->toBe([
        'the request body: could not be checked against the contract: Unresolved reference: /%24defs/Nowhere',
    ]);
});

/*
 * The guard, executed rather than asserted: a form body was the one body a suite could send and have
 * nothing looked at. These two are the same request under the two documents, and the point is that
 * NEITHER passes in silence.
 */
it('never lets a form body pass having checked nothing', function (bool $required): void {
    $result = checkFormContract(
        contractExchange('POST', '/api/uploads', status: 201, requestContentType: 'multipart/form-data', requestForm: ['title' => 'Receipt']),
        static function (array $document) use ($required): array {
            $document['paths']['/api/uploads']['post']['requestBody']['required'] = $required;

            return $document;
        },
    );

    expect($result->request->ok())->toBeFalse()
        ->and(formFindings($result))->toBe(['the request body: The required properties (quantity, attachment) are missing']);
})->with(['documented as required' => [true], 'documented as optional' => [false]]);
