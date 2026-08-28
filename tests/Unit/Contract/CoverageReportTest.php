<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Core\Contract\Coverage\OperationCoverage;
use Docuccino\Core\Contract\Coverage\ResponseCoverage;

/*
 * The gated number is documented RESPONSES, not operations: the `422` a consumer writes a catch against
 * is a promise of its own, and a suite that only ever asserts the happy path has proved none of them.
 * The fixture carries every shape that decision has to get right — a range key, a `default`, and a
 * response documented with no content at all.
 *
 * A documented WEBHOOK is a promise of the same kind, made in the other direction, so it is counted with
 * them: one `delivery` row apiece, because a webhook's own responses are what the RECEIVER answers and
 * nothing in the sending application's suite can exercise those.
 */

it('lists every documented operation in the document order, with the responses it promises', function (): void {
    $report = CoverageReport::of(contractIndex(), ['op:v1:aaaaainvoiceshow@200']);

    $rows = array_map(
        static fn (OperationCoverage $row): string => $row->label.' '.implode('|', array_map(
            static fn (ResponseCoverage $response): string => $response->status.'='.($response->exercised ? 'y' : 'n'),
            $row->responses,
        )),
        $report->rows,
    );

    // Routes in the document's order, then webhooks in the index's: by name, then by the canonical
    // method order, so a name published twice reads PUT before POST whatever the artifact spelled first.
    expect($rows)->toBe([
        'GET /api/exports 200=n',
        'GET /api/invoices 200=n',
        'POST /api/invoices 201=n|4XX=n',
        'GET /api/invoices/recent 200=n',
        'GET /api/invoices/{invoice} 200=y|default=n',
        'DELETE /api/invoices/{invoice} 204=n',
        'POST webhooks.alert.raised delivery=n',
        'POST webhooks.digest.sent delivery=n',
        'POST webhooks.export.ready delivery=n',
        'POST webhooks.invoice.paid delivery=n',
        'PUT webhooks.invoice.voided delivery=n',
        'POST webhooks.invoice.voided delivery=n',
        'POST webhooks.ping.sent delivery=n',
        'POST webhooks.receipt.sent delivery=n',
    ]);
});

it('counts a delivery the run recorded, and one it never did', function (): void {
    // The outbound half of the gate. Without this the report calls a document complete while every
    // webhook in it is unmeasured — the reading more generous than the truth the whole report exists to
    // stop, and the one nothing in the suite can reach over HTTP.
    $report = CoverageReport::of(contractIndex(), ['op:v1:aaaaainvoicepaid', 'op:v1:aaaaaaavoidedput']);

    $deliveries = [];
    foreach ($report->rows as $row) {
        if (str_contains($row->label, 'webhooks.')) {
            $deliveries[$row->label] = $row->responses[0]->status.'='.($row->responses[0]->exercised ? 'y' : 'n');
        }
    }

    expect($deliveries)->toBe([
        'POST webhooks.alert.raised' => 'delivery=n',
        'POST webhooks.digest.sent' => 'delivery=n',
        'POST webhooks.export.ready' => 'delivery=n',
        'POST webhooks.invoice.paid' => 'delivery=y',
        'PUT webhooks.invoice.voided' => 'delivery=y',
        'POST webhooks.invoice.voided' => 'delivery=n',
        'POST webhooks.ping.sent' => 'delivery=n',
        'POST webhooks.receipt.sent' => 'delivery=n',
    ])->and($report->exercisedResponses())->toBe(2);
});

it('counts responses and operations apart, and scores on responses', function (): void {
    $report = CoverageReport::of(contractIndex(), [
        'op:v1:aaaaainvoiceshow@200', 'op:v1:aaaainvoicestore@201', 'op:v1:notinthedocument@200',
    ]);

    // Six routes promising eight responses and eight webhooks promising a delivery apiece: the 201 and
    // the 200 are two of the sixteen, and the two operations they belong to still owe a `4XX` and a
    // `default` apiece.
    expect($report->totalOperations())->toBe(14)
        ->and($report->exercisedOperations())->toBe(2)
        ->and($report->totalResponses())->toBe(16)
        ->and($report->exercisedResponses())->toBe(2)
        ->and($report->percentage())->toBe(12.5)
        ->and($report->missing())->toHaveCount(14)
        ->and($report->complete())->toBeFalse();
});

it('reads a status through the operation’s own grammar, so a range and a default light up', function (): void {
    $report = CoverageReport::of(contractIndex(), [
        'op:v1:aaaainvoicestore@422',   // no `422` documented; the documented `4XX` is what it answered
        'op:v1:aaaaainvoiceshow@503',    // nothing but `default` could have described this
        'op:v1:aaaaainvoicekill@204',    // documented with no content at all, and still a promise kept
    ]);

    $exercised = [];
    foreach ($report->rows as $row) {
        foreach ($row->responses as $response) {
            if ($response->exercised) {
                $exercised[] = $row->label.' '.$response->status;
            }
        }
    }

    expect($exercised)->toBe([
        'POST /api/invoices 4XX',
        'GET /api/invoices/{invoice} default',
        'DELETE /api/invoices/{invoice} 204',
    ]);
});

it('counts an operation reached at a status it does not document, and no response of it', function (): void {
    // The status is undocumented, so the assertion failed — and the operation was still reached. Lighting
    // a response here would credit the run with a promise nothing checked.
    $report = CoverageReport::of(contractIndex(), ['op:v1:aaaaainvoicekill@500']);

    expect($report->exercisedOperations())->toBe(1)
        ->and($report->exercisedResponses())->toBe(0)
        ->and($report->rows[5]->exercised)->toBeTrue()
        ->and($report->rows[5]->complete())->toBeFalse();
});

it('reads a bare id as the operation reached and no response proved', function (): void {
    // Two writers spell this: a request-only assertion, which proves nothing about what came back, and a
    // log an older release wrote before statuses were recorded. Both may credit the operation and
    // neither may credit a response — a stale log can only ever read LOWER than the truth.
    $report = CoverageReport::of(contractIndex(), ['op:v1:aaaaainvoiceshow', 'op:v1:aaaainvoiceindex']);

    expect($report->exercisedOperations())->toBe(2)
        ->and($report->exercisedResponses())->toBe(0)
        ->and($report->percentage())->toBe(0.0)
        ->and($report->render())->toContain('0 of 16 documented responses and webhook deliveries exercised (0%)')
        ->toContain('2 of 14 documented operations were reached at all');
});

it('credits nothing for an entry that names no operation of this contract', function (): void {
    $report = CoverageReport::of(contractIndex(), ['GET /api/invoices', 'op:v1:aaaa', '', 'op:v1:aaaaainvoiceshow@200']);

    expect($report->exercisedResponses())->toBe(1)
        ->and($report->exercisedOperations())->toBe(1);
});

it('gives an operation that documents no response one row, and keeps it honest', function (bool $reached, string $shows): void {
    // A contract that promises nothing a status could name still owes the report a line, or an operation
    // nothing ever called would vanish from a response-granular listing altogether.
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => ['x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa']]]]]);
    $report = CoverageReport::of($index, $reached ? ['op:v1:aaaaaaaaaaaaaaaa@200'] : []);

    expect($report->totalResponses())->toBe(1)
        ->and($report->rows[0]->responses[0]->status)->toBeNull()
        ->and($report->rows[0]->responses[0]->exercised)->toBe($reached)
        ->and($report->render())->toContain($shows);
})->with([
    'never reached' => [false, 'GET /a  (no responses documented)  op:v1:aaaaaaaaaaaaaaaa'],
    'reached' => [true, '1 of 1 documented responses and webhook deliveries exercised (100%)'],
]);

it('orders an operation’s responses by family and then by status, whatever the document did', function (array $keys, array $ordered, array $unreachable): void {
    // The listing is a function of the key SET, never of the order the artifact happened to write them.
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => [
        'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
        'responses' => array_fill_keys($keys, ['description' => 'x']),
    ]]]]);

    $row = CoverageReport::of($index, [])->rows[0];

    $statuses = array_map(
        static fn (ResponseCoverage $response): ?string => $response->status,
        $row->responses,
    );

    expect($statuses)->toBe($ordered)
        ->and($row->unreachable)->toBe($unreachable);
})->with([
    'exact codes ascending' => [['404', '200', '201'], ['200', '201', '404'], []],
    'a range sorts where its family starts' => [['default', '4XX', '200'], ['200', '4XX', 'default'], []],
    'an exact code beats the range covering it' => [['4XX', '404'], ['404', '4XX'], []],
    'every family at once' => [['default', '5XX', '503', '1XX', '200'], ['200', '503', '1XX', '5XX', 'default'], []],
    'a key of no family is no response row at all' => [['default', 'weird', '2XX'], ['2XX', 'default'], ['weird']],
    'a range OAS does not spell is no response row either' => [['4xx', '200'], ['200'], ['4xx']],
    'written backwards, read the same' => [['4XX', '201'], ['201', '4XX'], []],
]);

it('calls an empty document covered rather than dividing by nothing', function (): void {
    $report = CoverageReport::of(ContractIndex::fromArray([]), []);

    expect($report->percentage())->toBe(100.0)
        ->and($report->complete())->toBeTrue()
        ->and($report->meets(100.0))->toBeTrue()
        ->and($report->render())->toBe(
            "Docuccino contract coverage: 0 of 0 documented responses and webhook deliveries exercised (100%).\n".
            '0 of 0 documented operations were reached at all.'
        );
});

it('clears a floor it exactly meets, and the one it prints', function (): void {
    $report = CoverageReport::of(contractIndex(), [
        'op:v1:aaaainvoiceindex@200', 'op:v1:aaaainvoicestore@201', 'op:v1:aaainvoicerecent@200',
        'op:v1:aaaaainvoiceshow@200', 'op:v1:aaaaaexportsfeed@200',
        'op:v1:aaaaaalertraised', 'op:v1:aaaaaadigestsent', 'op:v1:aaaaaexportready',
        'op:v1:aaaaainvoicepaid', 'op:v1:aaaaaaavoidedput',
    ]);

    // 10 of 16 is 62.5, which prints exactly — five responses and five deliveries, so a delivery is worth
    // exactly what a response is to the gated number. The operations line reads a far more generous
    // 10 of 14, which is the whole reason both are printed and only one is gated.
    expect($report->render())->toContain('(62.5%)')
        ->toContain('10 of 14 documented operations were reached at all')
        ->and($report->meets(62.5))->toBeTrue()
        ->and($report->meets(62.51))->toBeFalse()
        ->and($report->meets(50.0))->toBeTrue();
});

it('names the responses never exercised, and the honest floor to move to', function (): void {
    $rendered = CoverageReport::of(contractIndex(), [
        'op:v1:aaaainvoiceindex@200', 'op:v1:aaaainvoicestore@201', 'op:v1:aaainvoicerecent@200',
        'op:v1:aaaaainvoiceshow@200', 'op:v1:aaaaaexportsfeed@200', 'op:v1:aaaaainvoicekill@204',
        'op:v1:aaaaaalertraised', 'op:v1:aaaaaadigestsent', 'op:v1:aaaaaexportready', 'op:v1:aaaaainvoicepaid',
        'op:v1:aaaaaaavoidedput', 'op:v1:aaaaaavoidedpost', 'op:v1:aaaaaaaapingsent', 'op:v1:aaaaareceiptsent',
    ])->render(100.0);

    expect($rendered)->toContain('14 of 16 documented responses and webhook deliveries exercised (87.5%, floor 100%)')
        ->toContain('14 of 14 documented operations were reached at all — the floor is measured against responses and deliveries, not operations.')
        ->toContain('Never exercised:')
        // Every operation was reached and every webhook delivered; the two promises nobody proved are an
        // error response apiece.
        ->toContain('POST /api/invoices           4XX      op:v1:aaaainvoicestore')
        ->toContain('GET /api/invoices/{invoice}  default  op:v1:aaaaainvoiceshow')
        ->toContain('move the floor to 87 and ratchet it up');
});

it('names a webhook nobody delivered in the same listing, under the delivery it never made', function (): void {
    $rendered = CoverageReport::of(contractIndex(), [])->render(100.0);

    expect($rendered)->toContain('POST webhooks.invoice.paid      delivery      op:v1:aaaaainvoicepaid')
        ->toContain('PUT webhooks.invoice.voided     delivery      op:v1:aaaaaaavoidedput');
});

it('renders the same bytes whatever order the entries were recorded in', function (): void {
    $forwards = CoverageReport::of(contractIndex(), ['op:v1:aaaaainvoiceshow@200', 'op:v1:aaaainvoiceindex@200']);
    $backwards = CoverageReport::of(contractIndex(), ['op:v1:aaaainvoiceindex@200', 'op:v1:aaaaainvoiceshow@200']);

    expect($forwards->render(100.0))->toBe($backwards->render(100.0));
});

it('says so when an artifact carries no identities to track coverage by', function (): void {
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]]]);
    $rendered = CoverageReport::of($index, [])->render(100.0);

    // Core states the remedy without naming a command: it cannot know how an application exports,
    // and a framework's vocabulary in a framework-agnostic package would lie to any other adapter.
    expect($rendered)->toContain('GET /a  200  (no id)')
        ->toContain('1 of those carry no x-docuccino id')
        ->toContain('Export the artifact as UIR')
        ->not->toContain('artisan');
});

it('names the export command when the caller supplies one', function (): void {
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => []]]]);

    expect(CoverageReport::of($index, [])->render(100.0, 'php artisan docuccino:export --format=uir'))
        ->toContain('as OpenAPI with identities dropped (php artisan docuccino:export --format=uir).');
});

it('leaves the identity note off a report whose gaps all have ids', function (): void {
    expect(CoverageReport::of(contractIndex(), [])->render(100.0))->not->toContain('no x-docuccino id');
});

it('escapes a label, an id and a status out of the artifact, and measures each column on what it printed', function (): void {
    // What a pull request against a generated artifact would put in a path key, or a response key,
    // nobody re-reads.
    $forgery = "\n\x1b[32mAll contract assertions passed\x1b[0m";

    $index = contractIndex(static function (array $document) use ($forgery): array {
        $forged = $document['paths']['/api/exports'];
        $forged['get']['x-docuccino']['id'] = 'op:v1:aaaaforged'.$forgery;
        $forged['get']['responses'] = ['20'.$forgery => ['description' => 'Forged']];
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
        ->toContain('20\x0A\x1B[32mAll contract assertions passed\x1B[0m')
        ->not->toContain("\x1b")
        ->and(explode("\n", $rendered))->not->toContain('All contract assertions passed')
        // Every id starts in the same column, which only holds if both widths were measured after escaping.
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

it('names a response key no status can resolve to, and counts it in neither half', function (): void {
    // `4xx` and `ok` are valid response objects that pass the OAS meta-schema and that nothing can ever
    // exercise. Counted, they would put a 100% floor permanently out of reach; dropped silently, the
    // short denominator would be a mystery. So: out of both counts, and named.
    $index = contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['get']['responses']['4xx'] = ['description' => 'Bad'];
        $document['paths']['/api/invoices']['get']['responses']['ok'] = ['description' => 'Fine'];

        return $document;
    });

    $before = CoverageReport::of(contractIndex(), []);
    $after = CoverageReport::of($index, []);

    expect($after->totalResponses())->toBe($before->totalResponses())
        ->and($after->render())->toContain(
            '2 documented responses are named by a key no status can ever resolve to, so they are in neither '.
            'count above: GET /api/invoices 4xx, GET /api/invoices ok. A response key is a three-digit status, '.
            'an uppercase range (4XX), or default.',
        );
});

it('still names an unreachable response key on a report with nothing missing', function (): void {
    // The report a fully covered suite prints is exactly where a short denominator needs explaining, and
    // it is the one that returns before the listing.
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => [
        'x-docuccino' => ['id' => 'op:v1:0123456789abcdef'],
        'responses' => ['200' => ['description' => 'x'], 'twohundred' => ['description' => 'x']],
    ]]]]);

    $report = CoverageReport::of($index, ['op:v1:0123456789abcdef@200']);

    expect($report->complete())->toBeTrue()
        ->and($report->meets(100.0))->toBeTrue()
        ->and($report->render(100.0))->toContain(
            '1 documented response is named by a key no status can ever resolve to, so it is in neither '.
            'count above: GET /a twohundred.',
        );
});

it('says which kind of nothing an operation with no reachable response promises', function (): void {
    // "no responses documented" directly above a note naming two documented responses is the report
    // contradicting itself, so the column says which of the two reasons the row has no status.
    $index = ContractIndex::fromArray(['paths' => ['/a' => ['get' => [
        'x-docuccino' => ['id' => 'op:v1:0123456789abcdef'],
        'responses' => ['4xx' => ['description' => 'x']],
    ]]]]);

    expect(CoverageReport::of($index, [])->render())
        ->toContain('GET /a  (no response a status can name)  op:v1:0123456789abcdef')
        ->toContain('so it is in neither count above: GET /a 4xx')
        ->not->toContain('(no responses documented)');
});

it('leaves the unreachable note off a document whose response keys are all reachable', function (): void {
    expect(CoverageReport::of(contractIndex(), [])->render())->not->toContain('no status can ever resolve to');
});

it('escapes an unreachable response key out of the artifact', function (): void {
    $index = contractIndex(static function (array $document): array {
        $document['paths']['/api/invoices']['get']['responses']["ok\x1b[32m"] = ['description' => 'x'];

        return $document;
    });

    expect(CoverageReport::of($index, [])->render())
        ->toContain('GET /api/invoices ok\x1B[32m')
        ->not->toContain("\x1b");
});
