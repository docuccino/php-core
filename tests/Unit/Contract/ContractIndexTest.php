<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Diff\DocumentDiffer;

it('lists operations by path then canonical method order, whatever order the document holds them in', function (): void {
    $labels = array_map(
        static fn ($operation): string => $operation->label(),
        contractIndex()->operations(),
    );

    expect($labels)->toBe([
        'GET /api/exports',
        'GET /api/invoices',
        'POST /api/invoices',
        'GET /api/invoices/recent',
        'GET /api/invoices/{invoice}',
        'DELETE /api/invoices/{invoice}',
    ]);
});

it('orders operations by the document, not by the order paths were written', function (): void {
    $document = loadFixture('contract.uir.json');
    $reversed = ['paths' => array_reverse($document['paths'], true)] + $document;

    expect(array_map(static fn ($o): string => $o->label(), ContractIndex::fromArray($reversed)->operations()))
        ->toBe(array_map(static fn ($o): string => $o->label(), contractIndex()->operations()));
});

it('matches a concrete request path to its operation', function (string $method, string $path, ?string $label): void {
    $operation = contractIndex()->match($method, $path);

    expect($operation?->label())->toBe($label);
})->with([
    'a literal path' => ['GET', '/api/invoices', 'GET /api/invoices'],
    'a lower-case method' => ['get', '/api/invoices', 'GET /api/invoices'],
    'a placeholder' => ['GET', '/api/invoices/42', 'GET /api/invoices/{invoice}'],
    'a literal beats a placeholder that also matches' => ['GET', '/api/invoices/recent', 'GET /api/invoices/recent'],
    'a second method on the same path' => ['POST', '/api/invoices', 'POST /api/invoices'],
    'a method nothing documents' => ['PATCH', '/api/invoices', null],
    'a path nothing documents' => ['GET', '/api/credits', null],
]);

it('finds an operation by its stable id, and nothing by an id it does not carry', function (): void {
    expect(contractIndex()->operation('op:v1:aaaaainvoiceshow')?->label())->toBe('GET /api/invoices/{invoice}')
        ->and(contractIndex()->operation('op:v1:nosuchthing'))->toBeNull();
});

it('inherits path-item parameters, and lets an operation-level one of the same name win', function (): void {
    $show = contractIndex()->match('GET', '/api/invoices/9');

    expect(array_map(static fn ($p): string => $p->label(), $show?->parameters ?? []))->toBe(['path {invoice}']);

    $overridden = contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices/{invoice}']['get']['parameters'] = [
            ['name' => 'invoice', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
        ];

        return $document;
    })->match('GET', '/api/invoices/9');

    expect($overridden?->parameters[0]->schema())->toBe(['type' => 'string'])
        ->and($overridden?->parameters)->toHaveCount(1);
});

it('skips a parameter with no name or location rather than indexing half of one', function (): void {
    $index = contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['get']['parameters'][] = ['schema' => ['type' => 'string']];
        $document['paths']['/api/invoices']['get']['parameters'][] = 'not a parameter';

        return $document;
    });

    expect($index->match('GET', '/api/invoices')?->parameters)->toHaveCount(3);
});

it('reads a document with no paths, and one whose paths are not a map', function (): void {
    expect(ContractIndex::fromArray([])->operations())->toBe([])
        ->and(ContractIndex::fromArray(['paths' => 'nope'])->operations())->toBe([])
        ->and(ContractIndex::fromArray(['paths' => ['/a' => 'nope']])->operations())->toBe([]);
});

it('knows a UIR document from a plain OpenAPI export', function (): void {
    expect(contractIndex()->isUir())->toBeTrue()
        ->and(ContractIndex::fromArray(['openapi' => '3.2.0'])->isUir())->toBeFalse();
});

it('maps every identified node to where it lives, reading both id forms', function (): void {
    $identities = contractIndex()->identities();

    expect($identities['op:v1:aaaaainvoiceshow'])->toBe(['paths', '/api/invoices/{invoice}', 'get'])
        ->and($identities['sch:v1:aaaainvoiceshape'])->toBe(['components', 'schemas', 'Invoice'])
        ->and($identities['res:v1:aaaaaainvoiceone'])->toBe(['paths', '/api/invoices/{invoice}', 'get', 'responses', '200']);

    $flat = ContractIndex::fromArray(['paths' => ['/a' => ['get' => ['x-docuccino-id' => 'op:v1:flatform']]]]);

    expect($flat->identities())->toBe(['op:v1:flatform' => ['paths', '/a', 'get']])
        ->and($flat->operations()[0]->id)->toBe('op:v1:flatform');
});

it('reads the provenance recorded on a node named by id, and says nothing about one that is not there', function (): void {
    expect(contractIndex()->provenanceOf('sch:v1:aaaainvoiceshape')->lines())
        ->toBe(['integration:eloquent (integration) — app/Models/Invoice.php:22 in App\Models\Invoice'])
        ->and(contractIndex()->provenanceOf('op:v1:nosuchthing')->isEmpty())->toBeTrue();
});

it('decodes its own JSON, and refuses text that is not a JSON object', function (): void {
    expect(ContractIndex::fromJson('{"uir":"1.0.0"}')->isUir())->toBeTrue();

    expect(static fn () => ContractIndex::fromJson('not json'))->toThrow(JsonException::class);
    expect(static fn () => ContractIndex::fromJson('42'))->toThrow(JsonException::class, 'not a JSON object');
});

it('keeps an empty object distinct from an empty array in the graph it validates against', function (): void {
    $graph = ContractIndex::fromJson('{"components":{"schemas":{"Empty":{"properties":{}}}}}')->graph();

    expect($graph->components->schemas->Empty->properties)->toBeInstanceOf(stdClass::class);
});

it('hands the differ a document that keeps an empty object apart from an empty list', function (): void {
    // The index's own copy is decoded associatively, which cannot spell `{}` — so a document diffed
    // against ITSELF reported every empty-object example inside it changing shape. `comparable()` reads
    // the kept JSON text instead, exactly as `graph()` does for validation.
    $json = (string) json_encode([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '1.0.0'],
        'paths' => ['/forms' => ['get' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'responses' => ['200' => [
                'x-docuccino' => ['id' => 'res:v1:bbbbbbbbbbbbbbbb'],
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'example' => new stdClass]]],
            ]],
        ]]],
    ]);

    $index = ContractIndex::fromJson($json);

    expect((new DocumentDiffer)->diff($index->comparable(), $index->comparable())->isEmpty())->toBeTrue()
        // The lossy copy is still what the lookups walk, and it still reads that example as a list.
        ->and($index->document()['paths']['/forms']['get']['responses']['200']['content']['application/json']['schema']['example'])->toBe([]);
});
