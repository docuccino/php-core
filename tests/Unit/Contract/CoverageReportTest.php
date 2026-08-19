<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Coverage\CoverageReport;

it('lists every documented operation in the document order, exercised or not', function (): void {
    $report = CoverageReport::of(contractIndex(), ['op:v1:aaaainvoiceshow']);

    expect(array_map(static fn ($row): string => $row->label.'='.($row->exercised ? 'y' : 'n'), $report->rows))->toBe([
        'GET /api/exports=n',
        'GET /api/invoices=n',
        'POST /api/invoices=n',
        'GET /api/invoices/recent=n',
        'GET /api/invoices/{invoice}=y',
        'DELETE /api/invoices/{invoice}=n',
    ]);
});

it('counts and scores what the run covered', function (): void {
    $report = CoverageReport::of(contractIndex(), ['op:v1:aaaainvoiceshow', 'op:v1:aaaainvoiceindex', 'op:v1:notinthedocument']);

    expect($report->total())->toBe(6)
        ->and($report->exercisedCount())->toBe(2)
        ->and($report->missing())->toHaveCount(4)
        ->and($report->percentage())->toBeGreaterThan(33.3)
        ->and($report->complete())->toBeFalse();
});

it('calls an empty document covered rather than dividing by nothing', function (): void {
    $report = CoverageReport::of(ContractIndex::fromArray([]), []);

    expect($report->percentage())->toBe(100.0)
        ->and($report->complete())->toBeTrue()
        ->and($report->meets(100.0))->toBeTrue()
        ->and($report->render())->toBe('Docuccino contract coverage: 0 of 0 documented operations exercised (100%).');
});

it('clears a floor it exactly meets, and the one it prints', function (): void {
    $report = CoverageReport::of(contractIndex(), [
        'op:v1:aaaainvoiceindex', 'op:v1:aaaainvoicestore', 'op:v1:aaaainvoicerecent', 'op:v1:aaaainvoiceshow',
    ]);

    // 4 of 6 is 66.666…, which prints as 66.67 — a floor of 66.67 must therefore pass.
    expect($report->render())->toContain('(66.67%)')
        ->and($report->meets(66.67))->toBeTrue()
        ->and($report->meets(66.68))->toBeFalse()
        ->and($report->meets(50.0))->toBeTrue();
});

it('names what was never exercised, and the honest floor to move to', function (): void {
    $exercised = ['op:v1:aaaainvoiceindex', 'op:v1:aaaainvoicestore', 'op:v1:aaaainvoicerecent', 'op:v1:aaaainvoiceshow', 'op:v1:aaaaexportsfeed'];
    $rendered = CoverageReport::of(contractIndex(), $exercised)->render(100.0);

    expect($rendered)->toContain('5 of 6 documented operations exercised (83.33%, floor 100%)')
        ->toContain('Never exercised:')
        ->toContain('DELETE /api/invoices/{invoice}  op:v1:aaaainvoicekill')
        ->toContain('move the floor to 83 and ratchet it up');
});

it('renders the same bytes whatever order the ids were recorded in', function (): void {
    $forwards = CoverageReport::of(contractIndex(), ['op:v1:aaaainvoiceshow', 'op:v1:aaaainvoiceindex']);
    $backwards = CoverageReport::of(contractIndex(), ['op:v1:aaaainvoiceindex', 'op:v1:aaaainvoiceshow']);

    expect($forwards->render(100.0))->toBe($backwards->render(100.0));
});

it('says so when an artifact carries no identities to track coverage by', function (): void {
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => []]]]);
    $rendered = CoverageReport::of($index, [])->render(100.0);

    expect($rendered)->toContain('GET /a  (no id)')
        ->toContain('1 of those carry no x-docuccino id')
        ->toContain('--format=uir');
});

it('leaves the identity note off a report whose gaps all have ids', function (): void {
    expect(CoverageReport::of(contractIndex(), [])->render(100.0))->not->toContain('no x-docuccino id');
});

it('escapes a label and an id out of the artifact, and measures the column on what it printed', function (): void {
    // What a pull request against a generated artifact would put in a path key nobody re-reads.
    $forgery = "\n\x1b[32mAll contract assertions passed\x1b[0m";

    $index = contractIndex(static function (array $document) use ($forgery): array {
        $forged = $document['paths']['/api/exports'];
        $forged['get']['x-docuccino']['id'] = 'op:v1:aaaaforged'.$forgery;
        $document['paths']['/api/invoices'.$forgery] = $forged;

        return $document;
    });

    $rendered = CoverageReport::of($index, [])->render(100.0);
    $columns = array_map(
        static fn (string $row): int|false => strpos($row, 'op:v1:'),
        array_values(array_filter(explode("\n", $rendered), static fn (string $row): bool => str_contains($row, 'op:v1:'))),
    );

    expect($rendered)
        ->toContain('GET /api/invoices\x0A\x1B[32mAll contract assertions passed\x1B[0m')
        ->toContain('op:v1:aaaaforged\x0A\x1B[32mAll contract assertions passed\x1B[0m')
        ->not->toContain("\x1b")
        ->and(explode("\n", $rendered))->not->toContain('All contract assertions passed')
        // Every id starts in the same column, which only holds if the width was measured after escaping.
        ->and(array_unique($columns))->toHaveCount(1);
});

it('measures the label column in characters, so an accented path still lines up', function (): void {
    $index = contractIndex(static function (array $document): array {
        $document['paths']['/api/facturé'] = $document['paths']['/api/exports'];

        return $document;
    });

    $rendered = CoverageReport::of($index, [])->render();
    $columns = array_map(
        static fn (string $row): int|false => mb_strpos($row, 'op:v1:'),
        array_values(array_filter(explode("\n", $rendered), static fn (string $row): bool => str_contains($row, 'op:v1:'))),
    );

    expect($rendered)->toContain('GET /api/facturé')
        // Padding by bytes would leave this row one column short of every other one.
        ->and(array_unique($columns))->toHaveCount(1);
});
