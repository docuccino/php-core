<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;

it('names the operation, the failing member and the producer that wrote the schema', function (): void {
    $exchange = contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"INV-1","total":"12.50"}');
    $result = checkContract($exchange);

    $message = ContractMessages::exchange($result->operation, $exchange, $result);

    expect($message)
        ->toContain('GET /api/invoices/42 does not match the documented contract.')
        ->toContain('operation  GET /api/invoices/{invoice}  op:v1:aaaaainvoiceshow')
        ->toContain('status     200')
        ->toContain('the response body at /total')
        ->toContain('must match the type: number')
        ->toContain('schema   /components/schemas/Invoice/properties/total')
        ->toContain('from     integration:eloquent (integration) — app/Models/Invoice.php:31 in App\Models\Invoice::$total');
});

it('stops listing violations before the list stops being read', function (): void {
    $properties = [];
    $body = [];
    foreach (range(1, 15) as $n) {
        $properties['field'.$n] = ['type' => 'string'];
        $body['field'.$n] = $n;
    }

    $index = contractIndex(static function (array $document) use ($properties): array {
        $document['components']['schemas']['Invoice']['properties'] = $properties;
        $document['components']['schemas']['Invoice']['required'] = [];

        return $document;
    });

    $exchange = contractExchange('GET', '/api/invoices/42', responseBody: (string) json_encode($body));
    $result = (new ContractChecker($index))->check($exchange);

    expect(ContractMessages::exchange($result->operation, $exchange, $result))->toContain('… and 5 more.');
});

/*
 * The note channel. A check that could not read what the document published passes with a NOTE, and a
 * note nobody is told is a pass that proved nothing and said nothing — which is how a suite comes to
 * believe it has contract coverage it does not have.
 */
it('says an exchange passed having proved less than it looks like, naming every half that could not be read', function (): void {
    $exchange = contractExchange(
        'GET',
        '/api/exports',
        responseBody: 'a,b',
        responseContentType: 'text/csv',
        query: ['cursor' => 'abc'],
    );
    $result = checkContract($exchange, static function (array $document): array {
        // A query parameter documented with `content` rather than a schema: the request half's own way
        // of being uncheckable, so the line carries a finding from each half rather than only the last.
        $document['paths']['/api/exports']['get']['parameters'] = [
            ['name' => 'cursor', 'in' => 'query', 'content' => ['application/json' => []]],
        ];

        return $document;
    });

    expect(ContractMessages::uncheckedExchange($exchange, $result))->toBe(
        'GET /api/exports passed, but part of the contract was not checked: '.
        '?cursor is documented as a content object, which the check does not decode; '.
        'the response body is text/csv, which JSON Schema cannot check.'
    );
});

it('says nothing at all about an exchange it checked in full', function (): void {
    $exchange = contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"INV-1","total":12.5,"lines":[]}');

    expect(ContractMessages::uncheckedExchange($exchange, checkContract($exchange)))->toBeNull();
});

it('escapes an artifact string on its way into an exchange note', function (): void {
    // A parameter name out of a generated artifact nobody re-read, in a message that reaches a terminal.
    $forged = "cursor\x1b[32mAll contract assertions passed";
    $exchange = contractExchange('GET', '/api/exports', responseBody: 'a,b', responseContentType: 'text/csv', query: [$forged => 'abc']);
    $result = checkContract($exchange, static function (array $document) use ($forged): array {
        $document['paths']['/api/exports']['get']['parameters'] = [
            ['name' => $forged, 'in' => 'query', 'content' => ['application/json' => []]],
        ];

        return $document;
    });

    expect(ContractMessages::uncheckedExchange($exchange, $result))
        ->toContain('?cursor\x1B[32mAll contract assertions passed is documented as a content object')
        ->not->toContain("\x1b");
});

it('tells a reader which paths the contract does document for the method they tried', function (): void {
    $message = ContractMessages::undocumented(
        contractExchange('GET', '/api/credits'),
        contractIndex(),
        'Rebuild it: php artisan docuccino:export',
    );

    expect($message)
        ->toContain('GET /api/credits is not documented.')
        ->toContain('The contract documents these GET paths:')
        ->toContain('/api/invoices/{invoice}')
        ->toContain('Rebuild it: php artisan docuccino:export');
});

it('says plainly when the contract documents no such method at all', function (): void {
    expect(ContractMessages::undocumented(contractExchange('PATCH', '/api/invoices'), contractIndex()))
        ->toContain('The contract documents no PATCH operation at all.');
});

it('caps the list of documented paths', function (): void {
    $index = contractIndex(static function (array $document): array {
        foreach (range(1, 12) as $n) {
            $document['paths']['/api/thing'.$n] = ['get' => ['responses' => []]];
        }

        return $document;
    });

    expect(ContractMessages::undocumented(contractExchange('GET', '/api/credits'), $index))->toContain('… and 8 more.');
});

it('counts the examples it checked as well as the ones that lied', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['schemas']['Invoice']['properties']['total']['example'] = 'lots';

        return $document;
    })))->run();

    expect(ContractMessages::examples($report))
        ->toContain('1 of 4 documented examples does not match the schema beside it.')
        ->toContain('components/schemas/Invoice')
        ->toContain('at /components/schemas/Invoice/properties/total/example')
        ->toContain('from     integration:eloquent (integration) — app/Models/Invoice.php:31');
});

it('agrees the verb with how many lied, not with how many it checked', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['schemas']['Invoice']['properties']['total']['example'] = 'lots';
        $document['components']['schemas']['Line']['properties']['quantity']['example'] = 'several';

        return $document;
    })))->run();

    expect(ContractMessages::examples($report))
        ->toContain('2 of 4 documented examples do not match the schema beside them.');
});

it('names the examples nobody could check, rather than leaving them out of the count and the message', function (): void {
    // A refused schema is not a passing example. Dropping it from both the denominator and the body is
    // how a report comes to claim more than it proved.
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['schemas']['Invoice']['properties']['total']['example'] = 'lots';
        $document['components']['schemas']['Invoice']['properties']['ref'] = [
            'type' => 'object',
            'additionalProperties' => ['first_name'],
            'example' => ['first_name' => 'Ada'],
        ];

        return $document;
    })))->run();

    expect($report->uncheckable)->toHaveCount(1)
        ->and(ContractMessages::examples($report))
        ->toContain('1 of 4 documented examples does not match the schema beside it.')
        ->toContain('1 more could not be checked at all')
        ->toContain('at /components/schemas/Invoice/properties/ref/example')
        ->toContain('schema   /components/schemas/Invoice/properties/ref')
        ->toContain('additionalProperties must be a json schema (object or boolean)');
});

it('renders a breaking changeset the way the diff command does, and adds who wrote it', function (): void {
    $old = loadFixture('contract.uir.json');
    $new = $old;
    // A removed response is a breaking change the differ classifies on its own.
    unset($new['paths']['/api/invoices/{invoice}']['get']['responses']['200']);

    $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));

    $message = ContractMessages::breaking(
        $changeset,
        ContractIndex::fromArray($new),
        ContractIndex::fromArray($old),
        'Re-export it: php artisan docuccino:export',
    );

    expect($changeset->isBreaking())->toBeTrue()
        ->and($message)
        ->toContain('The current document makes 1 breaking change to the committed contract.')
        ->toContain('BREAKING')
        ->toContain('Re-export it: php artisan docuccino:export');
});

it('reads the provenance of a broken node off whichever side still has it', function (): void {
    $old = loadFixture('contract.uir.json');
    $new = $old;
    $new['components']['schemas']['Invoice']['properties']['total']['type'] = 'string';

    $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));

    expect(ContractMessages::breaking($changeset, ContractIndex::fromArray($new), ContractIndex::fromArray($old)))
        ->toContain('Where those changes came from:')
        ->toContain('integration:eloquent (integration) — app/Models/Invoice.php:22');
});

it('leaves the provenance block out when neither side recorded any', function (): void {
    $old = stripDocuccinoRecursive(loadFixture('contract.uir.json'));
    $new = $old;
    unset($new['paths']['/api/invoices/{invoice}']['get']['responses']['200']);

    $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));

    expect(ContractMessages::breaking($changeset, ContractIndex::fromArray($new), ContractIndex::fromArray($old)))
        ->not->toContain('Where those changes came from:');
});

it('says which file is stale and what changed in it', function (): void {
    $old = loadFixture('contract.uir.json');
    $new = $old;
    $new['paths']['/api/invoices/{invoice}']['get']['summary'] = 'Show one invoice.';

    $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));

    expect(ContractMessages::stale('docs/uir.json', $changeset, ContractIndex::fromArray($new), ContractIndex::fromArray($old), 'Regenerate it.'))
        ->toContain('docs/uir.json is out of date.')
        ->toContain('What changed since it was written:')
        ->toContain('Regenerate it.');
});

it('distinguishes a byte-level difference from a contract change', function (): void {
    expect(ContractMessages::stale('docs/uir.json', new Changeset, contractIndex(), contractIndex()))
        ->toContain('The contract itself is unchanged — the artifact differs only in bytes the emitters');
});

it('admits when a stale artifact cannot be compared semantically at all', function (): void {
    expect(ContractMessages::stale('docs/collection.postman.json', null, null, null, 'Regenerate it.'))
        ->toContain('docs/collection.postman.json is out of date.')
        ->toContain('not a document that can be')
        ->toContain('Regenerate it.');
});

it('shows an artifact its own escape sequences rather than obeying them', function (): void {
    // What a pull request against a generated artifact would put in a source.file nobody re-reads.
    $forgery = "\n\x1b[32mAll contract assertions passed\x1b[0m\n";

    $index = contractIndex(static function (array $document) use ($forgery): array {
        $document['components']['schemas']['Invoice']['properties']['total']['x-docuccino']['provenance'][0]['source']['file'] = 'app/Models/Invoice.php'.$forgery;
        $document['paths']['/api/invoices/{invoice}']['get']['x-docuccino']['id'] = 'op:v1:aaaaainvoiceshow'.$forgery;

        return $document;
    });

    $exchange = contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"INV-1","total":"12.50"}');
    $result = (new ContractChecker($index))->check($exchange);

    $message = ContractMessages::exchange($result->operation, $exchange, $result);

    expect($message)
        ->toContain('operation  GET /api/invoices/{invoice}  op:v1:aaaaainvoiceshow\x0A\x1B[32mAll contract assertions passed\x1B[0m\x0A')
        ->toContain('from     integration:eloquent (integration) — app/Models/Invoice.php\x0A\x1B[32mAll contract assertions passed\x1B[0m\x0A')
        ->not->toContain("\x1b")
        ->and(explode("\n", $message))->not->toContain('All contract assertions passed');
});

it('escapes a schema pointer built out of the artifact\'s own member names', function (): void {
    $property = "total\x1b[2K\rforged";

    $index = contractIndex(static function (array $document) use ($property): array {
        $schema = &$document['components']['schemas']['Invoice'];
        $schema['properties'][$property] = $schema['properties']['total'];
        unset($schema['properties']['total']);
        $schema['required'] = ['reference', $property];

        return $document;
    });

    $exchange = contractExchange('GET', '/api/invoices/42', responseBody: (string) json_encode([
        'reference' => 'INV-1',
        $property => 'not a number',
    ]));

    $result = (new ContractChecker($index))->check($exchange);
    $message = ContractMessages::exchange($result->operation, $exchange, $result);

    expect($message)
        ->toContain('schema   /components/schemas/Invoice/properties/total\x1B[2K\x0Dforged')
        ->not->toContain("\x1b")
        ->not->toContain("\r");
});

it('escapes the pointer an example finding carries', function (): void {
    $property = "total\x1b[31m";

    $report = (new ExampleAudit(contractIndex(static function (array $document) use ($property): array {
        $schema = &$document['components']['schemas']['Invoice'];
        $schema['properties'][$property] = ['type' => 'number', 'example' => 'lots'];
        $schema['required'] = ['reference'];

        return $document;
    })))->run();

    expect(ContractMessages::examples($report))
        ->toContain('at /components/schemas/Invoice/properties/total\x1B[31m/example')
        ->not->toContain("\x1b");
});
