<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Draft\SchemaKeywords;

it('passes a document whose examples all satisfy the schema beside them', function (): void {
    $report = (new ExampleAudit(contractIndex()))->run();

    expect($report->ok())->toBeTrue()
        ->and($report->checked)->toBe(3);
});

/*
 * The maximal fixture is written to exercise every UIR surface, not to be self-consistent, and it
 * turns out to disagree with itself: `precision` is documented `minimum: 0.5` and carries the example
 * `0.1`. That is the whole point of the audit, so it is pinned here rather than edited away — the
 * fixture is input other suites read byte-for-byte, and its goldens are locked.
 */
it('audits core’s own maximal fixture and finds the one example in it that lies', function (): void {
    $report = (new ExampleAudit(ContractIndex::fromArray(kitchenSink())))->run();

    expect($report->checked)->toBe(3)
        ->and($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->label)->toBe('GET /widgets/{id} → ?precision')
        ->and($report->findings[0]->pointer)->toBe('/paths/~1widgets~1{id}/get/parameters/2/example')
        ->and($report->findings[0]->violations[0]->message)->toBe('Number must be greater than or equal to 0.5');
});

it('catches an example on a response media type that lies about the shape', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['get']['responses']['200']['content']['application/json']['example'] = [
            ['total' => 'free'],
        ];

        return $document;
    })))->run();

    expect($report->ok())->toBeFalse()
        ->and($report->findings[0]->label)->toBe('GET /api/invoices → 200 application/json')
        ->and($report->findings[0]->pointer)
        ->toBe('/paths/~1api~1invoices/get/responses/200/content/application~1json/example');

    $messages = array_map(static fn ($v): string => $v->where(), $report->findings[0]->violations);

    expect($messages)->toContain('the example at /0')
        ->and($report->findings[0]->violations[0]->provenance->isEmpty())->toBeFalse();
});

it('catches a named example under a media type', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['get']['responses']['200']['content']['application/json']['examples'] = [
            'ok' => ['value' => []],
            'broken' => ['value' => [['reference' => 1, 'total' => 1]]],
            'no value member' => ['summary' => 'nothing to check'],
        ];

        return $document;
    })))->run();

    expect(array_map(static fn ($f): string => $f->pointer, $report->findings))
        ->toBe(['/paths/~1api~1invoices/get/responses/200/content/application~1json/examples/broken/value']);
});

it('catches an example on a request body and on a parameter', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['post']['requestBody']['content']['application/json']['example'] = ['reference' => 'x'];
        $document['paths']['/api/invoices']['get']['parameters'][0]['example'] = 'not an integer';

        return $document;
    })))->run();

    $labels = array_map(static fn ($f): string => $f->label, $report->findings);

    expect($labels)->toContain('GET /api/invoices → ?page')
        ->and($labels)->toContain('POST /api/invoices → request body application/json');
});

/*
 * The outbound half. A webhook publishes a body and response headers of exactly the same kind, and an
 * example beside one is copied by exactly the same reader — so the walk owes them the same audit. One
 * instance of a lie under `paths` means a class, and `webhooks` is the other member of it.
 */
it('catches a webhook example that lies about the payload it publishes', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['webhooks']['invoice.paid']['post']['requestBody']['content']['application/json']['example'] = ['total' => 'free'];

        return $document;
    })))->run();

    expect($report->ok())->toBeFalse()
        ->and($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->label)->toBe('POST webhooks.invoice.paid → request body application/json')
        ->and($report->findings[0]->pointer)
        ->toBe('/webhooks/invoice.paid/post/requestBody/content/application~1json/example');
});

it('catches a webhook response-header example that lies, the way it does a route’s', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['webhooks']['invoice.paid']['post']['responses']['200']['headers']['X-Delivery-Attempt'] = [
            'schema' => ['type' => 'integer'],
            'example' => 'first',
        ];

        return $document;
    })))->run();

    expect(array_map(static fn ($f): string => $f->label, $report->findings))
        ->toBe(['POST webhooks.invoice.paid → 200 header X-Delivery-Attempt'])
        ->and($report->findings[0]->pointer)
        ->toBe('/webhooks/invoice.paid/post/responses/200/headers/X-Delivery-Attempt/example');
});

it('finds one lie under paths and the same lie under webhooks, and finds them both', function (): void {
    // The same example, written twice into the two halves of one document. Either half going unwalked
    // reads here as a count of one, which is what a sweep stopping one member short looks like.
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $lie = ['total' => 'free'];
        $document['paths']['/api/invoices']['post']['requestBody']['content']['application/json']['example'] = $lie;
        $document['webhooks']['invoice.paid']['post']['requestBody']['content']['application/json']['example'] = $lie;

        return $document;
    })))->run();

    expect(array_map(static fn ($f): string => $f->label, $report->findings))->toBe([
        'POST /api/invoices → request body application/json',
        'POST webhooks.invoice.paid → request body application/json',
    ]);
});

it('walks a webhook body reached through a $ref, and audits every webhook once', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['requestBodies']['InvoiceDelivery'] = $document['webhooks']['invoice.paid']['post']['requestBody'];
        $document['components']['requestBodies']['InvoiceDelivery']['content']['application/json']['example'] = ['total' => 'free'];
        $document['webhooks']['invoice.paid']['post']['requestBody'] = ['$ref' => '#/components/requestBodies/InvoiceDelivery'];

        return $document;
    })))->run();

    // The pointer names where a reader would go and look, which is the component the `$ref` lands on.
    expect($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->pointer)
        ->toBe('/components/requestBodies/InvoiceDelivery/content/application~1json/example');
});

it('catches an example nested inside a component schema', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['schemas']['Invoice']['properties']['total']['example'] = 'lots';

        return $document;
    })))->run();

    expect($report->findings[0]->label)->toBe('components/schemas/Invoice')
        ->and($report->findings[0]->pointer)->toBe('/components/schemas/Invoice/properties/total/example')
        ->and($report->findings[0]->violations[0]->provenance->lines())
        ->toBe(['integration:eloquent (integration) — app/Models/Invoice.php:31 in App\Models\Invoice::$total']);
});

it('reads a schema’s own examples list, which is a list of instances rather than a map', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['schemas']['Problem']['properties']['detail']['examples'] = ['fine', 42];

        return $document;
    })))->run();

    expect($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->pointer)->toBe('/components/schemas/Problem/properties/detail/examples/1');
});

it('audits a component once, however many operations reference it', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['schemas']['Invoice']['properties']['reference']['example'] = 9;

        return $document;
    })))->run();

    // Invoice is referenced by four operations; the finding is reported once, from the component.
    expect($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->label)->toBe('components/schemas/Invoice');
});

/**
 * One case per keyword that carries a subschema, built from the table rather than listed. A hand
 * dataset stood here naming seven of them, while the audit's own hand list was short by five — so the
 * dataset agreed with the walk about exactly the keywords both had forgotten.
 *
 * @return array<string, array{0: array<string, mixed>, 1: string}>
 */
function exampleAuditSubschemaPositions(): array
{
    $lying = ['type' => 'string', 'example' => 1];
    $at = '/components/schemas/Problem/';

    $cases = [];

    foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA) as $keyword) {
        $cases[$keyword] = [[$keyword => $lying], $at.$keyword.'/example'];
    }

    foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_MAP) as $keyword) {
        $cases[$keyword] = [[$keyword => ['Inner' => $lying]], $at.$keyword.'/Inner/example'];
    }

    foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST) as $keyword) {
        $cases[$keyword] = [[$keyword => [$lying]], $at.$keyword.'/0/example'];
    }

    return $cases;
}

it('descends through every schema keyword that holds schemas', function (array $schema, string $pointer): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document) use ($schema): array {
        $document['components']['schemas']['Problem'] = $schema;

        return $document;
    })))->run();

    expect(array_map(static fn ($f): string => $f->pointer, $report->findings))->toBe([$pointer]);
})->with(exampleAuditSubschemaPositions());

/**
 * Every keyword the table positions, reached by a route the generator above does not take:
 * {@see SchemaKeywords::objectValued()} is the whole table bar the list-valued applicators, so the two
 * together are all of it. Read this way, the expectation is the TABLE's, not a restatement of the three
 * `at()` calls the dataset is built from — which would agree with the generator about anything both
 * forgot.
 *
 * @return list<string>
 */
function exampleAuditPositionedKeywords(): array
{
    return [...SchemaKeywords::objectValued(), ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST)];
}

it('builds a case for every subschema-carrying keyword the table names', function (): void {
    // Anti-vacuity for the dataset above: a generator that stopped seeing a position would quietly
    // stop proving the walk reaches it, which is exactly how five went unaudited. An exact count stood
    // here, which fails on a keyword ADDED correctly as loudly as on one dropped — so it asked to be
    // bumped rather than read. What it was for is this: one case per positioned keyword that carries a
    // schema, which the table itself can answer.
    $cases = exampleAuditSubschemaPositions();

    $expected = array_values(array_filter(
        exampleAuditPositionedKeywords(),
        // `dependentRequired` carries string lists rather than schemas, so it is the one positioned
        // keyword with no case — and the only one.
        static fn (string $keyword): bool => SchemaKeywords::positionOf($keyword) !== SchemaKeywords::POSITION_STRING_LIST_MAP,
    ));

    $actual = array_keys($cases);
    sort($expected);
    sort($actual);

    // A floor beside the equality, because both sides could go empty together: it EQUALS the count on
    // the tree, so a keyword added passes and a table that stopped answering fails.
    expect($actual)->toBe($expected)
        ->and($cases)->toHaveCount(count($expected))
        ->and(count($cases))->toBeGreaterThanOrEqual(21)
        ->and($actual)->not->toContain('dependentRequired');
});

it('never follows a $ref while descending, so a recursive schema terminates', function (): void {
    $report = (new ExampleAudit(contractIndex()))->run();

    // Line refers to itself through `child`; the audit still finishes and still sees Line's own example.
    expect($report->checked)->toBe(3);
});

it('finds nothing in a document with no examples at all', function (): void {
    $report = (new ExampleAudit(ContractIndex::fromArray(['paths' => ['/a' => ['get' => []]]])))->run();

    expect($report->checked)->toBe(0)
        ->and($report->ok())->toBeTrue();
});

/*
 * The audit walks every example in the document, and the validator parses each subject as it reaches
 * it — so a schema it will not parse throws rather than failing. Before this it took the rest of the
 * walk, and the build that asked, with it: one free-form map carrying an example was a dead export.
 *
 * The malformed shape here is a `additionalProperties` holding a real list, which nothing repairs and
 * nothing should: an empty array IS the empty schema and is coerced (see SchemaCheckTest), a populated
 * one is not a schema at all.
 */
it('records a schema the validator will not read instead of throwing, and audits the rest anyway', function (): void {
    $report = (new ExampleAudit(ContractIndex::fromArray([
        'paths' => [],
        'components' => ['schemas' => [
            // Sorts first, so the walk reaches the unreadable schema BEFORE the one that lies: a guard
            // that merely wrapped the whole run would report neither.
            'Prefill' => [
                'type' => 'object',
                'properties' => ['suggestions' => [
                    'type' => 'object',
                    'additionalProperties' => ['first_name', 'last_name'],
                    'example' => ['first_name' => 'Ada'],
                ]],
            ],
            'Widget' => ['type' => 'object', 'properties' => ['active' => ['type' => 'boolean', 'example' => 'no']]],
        ]],
    ])))->run();

    // One of the two sites was refused, so only one was checked: counting the refused one in the
    // denominator would make the report read as having proved more than it did.
    expect($report->checked)->toBe(1)
        ->and($report->uncheckable)->toHaveCount(1)
        ->and($report->uncheckable[0]->pointer)->toBe('/components/schemas/Prefill/properties/suggestions/example')
        ->and($report->uncheckable[0]->schemaPointer)->toBe('/components/schemas/Prefill/properties/suggestions')
        ->and($report->uncheckable[0]->label)->toBe('components/schemas/Prefill')
        ->and($report->uncheckable[0]->reason)->toBe('additionalProperties must be a json schema (object or boolean)')
        // An unreadable schema is not a finding: the audit knows nothing about that example either way,
        // so `ok()` still answers only for the examples it managed to check.
        ->and($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->pointer)->toBe('/components/schemas/Widget/properties/active/example');
});

it('audits the example beside a documented response header, which nothing else verifies', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['post']['responses']['201']['headers']['X-RateLimit-Remaining']['example'] = 'plenty';

        return $document;
    })))->run();

    expect($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->label)->toBe('POST /api/invoices → 201 header X-RateLimit-Remaining')
        ->and($report->findings[0]->pointer)
        ->toBe('/paths/~1api~1invoices/post/responses/201/headers/X-RateLimit-Remaining/example')
        ->and($report->findings[0]->violations[0]->message)->toBe('The data (string) must match the type: integer');
});

it('follows a $ref out of the headers map and audits an example inside the header’s own schema', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['headers']['Legacy']['schema']['example'] = 'yes';

        return $document;
    })))->run();

    expect(array_map(static fn ($f): string => $f->pointer, $report->findings))
        ->toBe(['/components/headers/Legacy/schema/example']);
});

it('counts an example with no schema beside it as uncheckable rather than as checked', function (): void {
    // `check()` answers "no violations" both where nothing disagreed and where there was no schema to
    // disagree with. Counting the second as checked inflates the denominator the report renders, so
    // "0 of 4 examples do not match" claims four examples were held to a contract.
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['post']['responses']['201']['headers']['X-Trace'] = [
            'description' => 'Whatever the tracer emitted.',
            'example' => 'abc123',
        ];

        return $document;
    })))->run();

    expect($report->checked)->toBe(3)
        ->and($report->findings)->toBe([])
        ->and($report->uncheckable)->toHaveCount(1)
        ->and($report->uncheckable[0]->pointer)
        ->toBe('/paths/~1api~1invoices/post/responses/201/headers/X-Trace/example')
        ->and($report->uncheckable[0]->label)->toBe('POST /api/invoices → 201 header X-Trace')
        ->and($report->uncheckable[0]->reason)->toBe('the contract puts no schema beside it');
});

it('leaves an example beside a Content-Type response header unaudited, as the check does', function (): void {
    // OAS says a response header of that name SHALL be ignored — `content` describes the media type —
    // so auditing an example beside one would hold a document to a claim nothing enforces. The audit
    // reads the same index the assertions do, so the two can never disagree about which headers exist.
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['post']['responses']['201']['headers']['Content-Type']['example'] = 'not an integer';

        return $document;
    })))->run();

    expect($report->ok())->toBeTrue()
        ->and($report->checked)->toBe(3)
        ->and($report->uncheckable)->toBe([]);
});

it('audits response header examples in name order rather than in the document’s key order', function (array $headers): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document) use ($headers): array {
        $document['paths']['/api/invoices']['post']['responses']['201']['headers'] = $headers;

        return $document;
    })))->run();

    expect(array_map(static fn ($f): string => $f->label, $report->findings))->toBe([
        'POST /api/invoices → 201 header X-A',
        'POST /api/invoices → 201 header X-B',
        'POST /api/invoices → 201 header X-C',
    ]);
})->with(function () {
    $lying = ['schema' => ['type' => 'integer'], 'example' => 'no'];

    return [
        'written in order' => [['X-A' => $lying, 'X-B' => $lying, 'X-C' => $lying]],
        'written out of order' => [['X-C' => $lying, 'X-A' => $lying, 'X-B' => $lying]],
    ];
});

/*
 * A `$ref` that names nothing the document defines is a broken document, not an uncheckable one —
 * `ContractChecker` has always failed on it, and the audit used to walk past it: no `content` under a
 * pointer node, so every example beneath it silently left the walk AND the `checked` count, and a
 * suite reported that all the examples it could find were fine.
 */
it('reports a reference the document does not define rather than skipping what is behind it', function (string $where, callable $break, string $label, string $pointer): void {
    $report = (new ExampleAudit(contractIndex($break)))->run();

    $unresolvedRefs = array_values(array_filter($report->findings, static fn ($f): bool => $f->unresolvedRef !== null));

    expect($report->ok())->toBeFalse()
        ->and($unresolvedRefs)->toHaveCount(1)
        ->and($unresolvedRefs[0]->unresolvedRef)->toBe('#/components/'.$where)
        ->and($unresolvedRefs[0]->label)->toBe($label)
        ->and($unresolvedRefs[0]->pointer)->toBe($pointer)
        ->and($unresolvedRefs[0]->violations[0]->message)->toBe('is documented at #/components/'.$where.', which the contract does not define')
        ->and($report->uncheckable)->toBe([]);
})->with([
    'a request body' => [
        'requestBodies/Gone',
        static function (array $document): array {
            $document['paths']['/api/invoices']['post']['requestBody'] = ['$ref' => '#/components/requestBodies/Gone'];

            return $document;
        },
        'POST /api/invoices → request body',
        '/paths/~1api~1invoices/post/requestBody',
    ],
    'a response' => [
        'responses/Gone',
        static function (array $document): array {
            $document['paths']['/api/invoices']['get']['responses']['200'] = ['$ref' => '#/components/responses/Gone'];

            return $document;
        },
        'GET /api/invoices → 200',
        '/paths/~1api~1invoices/get/responses/200',
    ],
    'a path item' => [
        'pathItems/Gone',
        static function (array $document): array {
            $document['paths']['/api/exports'] = ['$ref' => '#/components/pathItems/Gone'];

            return $document;
        },
        '/api/exports',
        '/paths/~1api~1exports',
    ],
    'a webhook path item' => [
        'pathItems/Gone',
        static function (array $document): array {
            $document['webhooks']['invoice.paid'] = ['$ref' => '#/components/pathItems/Gone'];

            return $document;
        },
        'webhooks.invoice.paid',
        '/webhooks/invoice.paid',
    ],
]);

it('keeps auditing every other example once one reference is broken', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['post']['requestBody'] = ['$ref' => '#/components/requestBodies/Gone'];

        return $document;
    })))->run();

    // The fixture's other examples still went through the validator: one broken pointer costs the
    // audit what is behind it and nothing else.
    expect($report->checked)->toBeGreaterThan(0)
        ->and(array_filter($report->findings, static fn ($f): bool => $f->unresolvedRef === null))->toBe([]);
});

it('counts a broken reference apart from an example that failed its schema', function (): void {
    $message = ContractMessages::examples((new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['get']['responses']['200'] = ['$ref' => '#/components/responses/Gone'];

        return $document;
    })))->run());

    expect($message)->toContain('0 of ')
        ->toContain('1 reference names something the contract does not define')
        ->toContain('#/components/responses/Gone');
});

/*
 * An entry of an `examples` map is an Example Object OR a Reference Object naming one in
 * `components.examples`. Requiring `value` on the node as written meant a shared example — the very
 * one several operations copy — was never held to any schema at all.
 */
it('audits an example shared through components.examples', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['examples']['Wrong'] = ['value' => ['total' => 'free']];
        $document['paths']['/api/invoices']['get']['responses']['200']['content']['application/json']['examples'] = [
            'shared' => ['$ref' => '#/components/examples/Wrong'],
        ];

        return $document;
    })))->run();

    // Audited at the component it lives in — where a reader would go and edit it — and against the
    // schema at the use site, which is the contract it has to satisfy.
    expect($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->pointer)->toBe('/components/examples/Wrong/value')
        ->and($report->findings[0]->label)->toBe('GET /api/invoices → 200 application/json')
        ->and($report->findings[0]->unresolvedRef)->toBeNull();
});

it('checks a shared example exactly as many times as an inline one', function (): void {
    $shared = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['examples']['Right'] = ['value' => [['total' => 1]]];
        $document['paths']['/api/invoices']['get']['responses']['200']['content']['application/json']['examples'] = [
            'ok' => ['$ref' => '#/components/examples/Right'],
        ];

        return $document;
    })))->run();

    $inline = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['get']['responses']['200']['content']['application/json']['examples'] = [
            'ok' => ['value' => [['total' => 1]]],
        ];

        return $document;
    })))->run();

    // Whether that value satisfies the fixture's schema is not the point — that the two spellings are
    // judged identically is, so the verdicts are compared rather than asserted good.
    expect($shared->checked)->toBe($inline->checked)
        ->and(array_map(static fn ($f): array => [$f->label, count($f->violations)], $shared->findings))
        ->toBe(array_map(static fn ($f): array => [$f->label, count($f->violations)], $inline->findings))
        ->and($inline->checked)->toBeGreaterThan(3);
});

it('reports an example reference the document does not define', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['get']['responses']['200']['content']['application/json']['examples'] = [
            'shared' => ['$ref' => '#/components/examples/Gone'],
        ];

        return $document;
    })))->run();

    $unresolvedRefs = array_values(array_filter($report->findings, static fn ($f): bool => $f->unresolvedRef !== null));

    expect($unresolvedRefs)->toHaveCount(1)
        ->and($unresolvedRefs[0]->unresolvedRef)->toBe('#/components/examples/Gone')
        ->and($unresolvedRefs[0]->label)->toBe('GET /api/invoices → 200 application/json → example shared')
        ->and($unresolvedRefs[0]->pointer)->toBe('/paths/~1api~1invoices/get/responses/200/content/application~1json/examples/shared');
});
