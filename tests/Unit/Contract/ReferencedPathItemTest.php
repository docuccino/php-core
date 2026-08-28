<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Core\Contract\Examples\ExampleAudit;

/*
 * A path item may be written as `{"$ref": "#/components/pathItems/X"}` wherever a path item may be
 * written at all, and a reader that inspects the node by hand finds no `get` under it — so the path
 * publishes nothing, the checker reports an endpoint the document fully describes as undocumented,
 * and the operation leaves the coverage denominator with it.
 *
 * The whole point is that a reference is a SPELLING: everything below compares the referenced document
 * against the same fixture written inline, so nothing here can pass by agreeing with itself.
 */

it('indexes a $ref\'ed path item exactly as the inline one', function (string $template): void {
    $referenced = ContractIndex::fromArray(contractWithReferencedPathItems([$template]));

    expect(contractIndexShape($referenced))->toBe(contractIndexShape(contractIndex()));
})->with(function (): array {
    $templates = array_map(strval(...), array_keys(loadFixture('contract.uir.json')['paths']));

    // A scan that matched nothing must fail rather than pass: the fixture is the source of truth for
    // which paths there are, so a fixture that stopped having any fails here rather than proving
    // nothing quietly.
    expect($templates)->toHaveCount(4);

    return array_combine($templates, array_map(static fn (string $t): array => [$t], $templates));
});

it('indexes a document whose every path item is referenced exactly as the inline one', function (): void {
    $referenced = ContractIndex::fromArray(contractWithReferencedPathItems());

    expect(contractIndexShape($referenced))->toBe(contractIndexShape(contractIndex()))
        ->and($referenced->operations())->toHaveCount(6);
});

it('carries a referenced path item\'s shared parameters onto its operations', function (): void {
    // The one fixture path with path-item level `parameters`. They live on the component now, so an
    // index reading them off the use site would produce an operation with no path parameter at all.
    $referenced = ContractIndex::fromArray(contractWithReferencedPathItems(['/api/invoices/{invoice}']));
    $operation = $referenced->match('GET', '/api/invoices/42');

    expect($operation?->label())->toBe('GET /api/invoices/{invoice}')
        ->and(array_map(static fn ($p): string => $p->in.':'.$p->name, $operation?->parameters ?? []))
        ->toBe(['path:invoice']);
});

it('follows a chain of path-item references', function (): void {
    $document = contractWithReferencedPathItems(['/api/exports']);
    $document['components']['pathItems']['Hop'] = ['$ref' => '#/components/pathItems/Sharedapiexports'];
    $document['paths']['/api/exports'] = ['$ref' => '#/components/pathItems/Hop'];

    expect(contractIndexShape(ContractIndex::fromArray($document)))->toBe(contractIndexShape(contractIndex()));
});

it('indexes a $ref\'ed webhook path item exactly as the inline one', function (): void {
    $referenced = ContractIndex::fromArray(contractWithReferencedPathItems([], 'webhooks'));

    expect(contractIndexShape($referenced))->toBe(contractIndexShape(contractIndex()))
        ->and($referenced->webhooks())->toHaveCount(8)
        ->and($referenced->webhookNames())->toContain('invoice.paid');
});

it('counts a referenced path item in the coverage denominator', function (): void {
    $inline = CoverageReport::of(contractIndex(), []);
    $referenced = CoverageReport::of(ContractIndex::fromArray(contractWithReferencedPathItems()), []);

    expect($referenced->totalResponses())->toBe($inline->totalResponses())
        ->and($referenced->totalOperations())->toBe($inline->totalOperations())
        ->and($inline->totalResponses())->toBeGreaterThan(6);
});

it('reads examples under a referenced path item', function (): void {
    $inline = (new ExampleAudit(contractIndex()))->run();
    $referenced = (new ExampleAudit(ContractIndex::fromArray(contractWithReferencedPathItems())))->run();

    expect($referenced->checked)->toBe($inline->checked)
        ->and($inline->checked)->toBeGreaterThan(0);
});

it('publishes no operations for a reference nothing defines, and names the reference', function (): void {
    $document = contractWithReferencedPathItems(['/api/exports']);
    $document['paths']['/api/exports'] = ['$ref' => '#/components/pathItems/Gone'];

    $index = ContractIndex::fromArray($document);

    expect(array_map(static fn ($o): string => $o->label(), $index->operations()))
        ->not->toContain('GET /api/exports')
        ->and($index->unresolvedPaths())->toBe(['/api/exports' => '#/components/pathItems/Gone']);
});

it('terminates on a cycle of path-item references and reports it as unresolved', function (): void {
    $document = loadFixture('contract.uir.json');
    $document['components']['pathItems'] = [
        'A' => ['$ref' => '#/components/pathItems/B'],
        'B' => ['$ref' => '#/components/pathItems/A'],
    ];
    $document['paths']['/api/exports'] = ['$ref' => '#/components/pathItems/A'];

    $index = ContractIndex::fromArray($document);

    expect($index->unresolvedPaths())->toBe(['/api/exports' => '#/components/pathItems/A'])
        ->and($index->match('GET', '/api/exports'))->toBeNull();
});

it('tells a reader the pointer is broken rather than that the route is undocumented', function (): void {
    $document = contractWithReferencedPathItems(['/api/exports']);
    $document['paths']['/api/exports'] = ['$ref' => '#/components/pathItems/Gone'];

    $message = ContractMessages::undocumented(
        contractExchange('GET', '/api/exports'),
        ContractIndex::fromArray($document),
        'Rebuild it.',
    );

    expect($message)
        ->toContain('GET /api/exports is documented behind a reference the contract does not define')
        ->toContain('#/components/pathItems/Gone')
        ->toContain('Rebuild it.')
        ->not->toContain('The artifact predates this route');
});

it('says the same about a webhook behind a broken reference', function (): void {
    $document = loadFixture('contract.uir.json');
    $document['webhooks']['invoice.paid'] = ['$ref' => '#/components/pathItems/Gone'];

    $index = ContractIndex::fromArray($document);

    expect($index->unresolvedWebhooks())->toBe(['invoice.paid' => '#/components/pathItems/Gone'])
        ->and(ContractMessages::undocumentedWebhook('invoice.paid', 'post', $index))
        ->toContain('POST webhooks.invoice.paid is documented behind a reference the contract does not define')
        ->toContain('#/components/pathItems/Gone');
});

it('picks the most specific unresolved template when two of them bind the path', function (): void {
    $document = loadFixture('contract.uir.json');
    $document['paths']['/api/invoices/recent'] = ['$ref' => '#/components/pathItems/Literal'];
    $document['paths']['/api/invoices/{invoice}'] = ['$ref' => '#/components/pathItems/Placeholder'];

    $message = ContractMessages::undocumented(
        contractExchange('GET', '/api/invoices/recent'),
        ContractIndex::fromArray($document),
    );

    expect($message)->toContain('#/components/pathItems/Literal')
        ->not->toContain('#/components/pathItems/Placeholder');
});
