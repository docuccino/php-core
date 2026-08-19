<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;

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

it('descends through every schema keyword that holds schemas', function (array $schema, string $pointer): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document) use ($schema): array {
        $document['components']['schemas']['Problem'] = $schema;

        return $document;
    })))->run();

    expect(array_map(static fn ($f): string => $f->pointer, $report->findings))->toBe([$pointer]);
})->with([
    'properties' => [
        ['properties' => ['a' => ['type' => 'string', 'example' => 1]]],
        '/components/schemas/Problem/properties/a/example',
    ],
    'items' => [
        ['type' => 'array', 'items' => ['type' => 'string', 'example' => 1]],
        '/components/schemas/Problem/items/example',
    ],
    'allOf' => [
        ['allOf' => [['type' => 'string', 'example' => 1]]],
        '/components/schemas/Problem/allOf/0/example',
    ],
    'oneOf' => [
        ['oneOf' => [['type' => 'string'], ['type' => 'integer', 'example' => 'x']]],
        '/components/schemas/Problem/oneOf/1/example',
    ],
    'additionalProperties' => [
        ['additionalProperties' => ['type' => 'string', 'example' => 1]],
        '/components/schemas/Problem/additionalProperties/example',
    ],
    '$defs' => [
        ['$defs' => ['Inner' => ['type' => 'string', 'example' => 1]]],
        '/components/schemas/Problem/$defs/Inner/example',
    ],
    'patternProperties' => [
        ['patternProperties' => ['^a' => ['type' => 'string', 'example' => 1]]],
        '/components/schemas/Problem/patternProperties/^a/example',
    ],
]);

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
