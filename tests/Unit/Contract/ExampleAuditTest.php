<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Draft\SchemaKeywords;

it('passes a document whose examples all satisfy the schema beside them', function (): void {
    $report = (new ExampleAudit(contractIndex()))->run();

    expect($report->ok())->toBeTrue()
        ->and($report->checked)->toBe(2);
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

it('builds a case for every subschema-carrying keyword the table names', function (): void {
    // Anti-vacuity for the dataset above: a generator that stopped seeing a position would quietly
    // stop proving the walk reaches it, which is exactly how five went unaudited.
    $cases = exampleAuditSubschemaPositions();

    expect($cases)->toHaveCount(21)
        ->and(array_keys($cases))->toContain('if', 'then', 'else', 'unevaluatedItems', 'unevaluatedProperties')
        ->and(array_keys($cases))->toContain('properties', 'items', 'allOf', '$defs', 'contentSchema', 'definitions')
        // `dependentRequired` carries string lists rather than schemas, so it is the one positioned
        // keyword with no case — and the only one.
        ->and(array_keys($cases))->not->toContain('dependentRequired');
});

it('never follows a $ref while descending, so a recursive schema terminates', function (): void {
    $report = (new ExampleAudit(contractIndex()))->run();

    // Line refers to itself through `child`; the audit still finishes and still sees Line's own example.
    expect($report->checked)->toBe(2);
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

    expect($report->checked)->toBe(2)
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
