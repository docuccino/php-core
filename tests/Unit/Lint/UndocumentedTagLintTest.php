<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Lint\LintRuleOptions;
use Docuccino\Core\Lint\UndocumentedTagLint;

/**
 * The tag-coverage lint, whose safety rests on one guard plus the off-switch: it says nothing unless
 * the document declares tags at all, because "undeclared" only means something once the others have
 * descriptions — and it stays off besides, for the mixed case that guard cannot see.
 */
$on = new LintRuleOptions(enabled: true);
it('warns once per undeclared tag, naming an operation that carries it', function () use ($on): void {
    $document = lintDocument(
        ['GET /api/reports' => ['tags' => ['Reports']], 'GET /api/z' => ['tags' => ['Reports']]],
        [['name' => 'Invoices', 'description' => 'Billing documents.']],
    );

    $findings = lintDiagnostics(new UndocumentedTagLint($on), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('lint.undocumented-tag')
        ->and($findings[0]->message)->toContain('"Reports"')
        ->and($findings[0]->message)->toContain('GET /api/reports')
        ->and($findings[0]->help)->toContain('lint.tags.allow');
});

it('says nothing when the document declares no tags at all', function () use ($on): void {
    $document = lintDocument(['GET /api/reports' => ['tags' => ['Reports']]]);

    expect(lintDiagnostics(new UndocumentedTagLint($on), $document))->toBe([]);
});

it('says nothing about a tag the document declares', function () use ($on): void {
    $document = lintDocument(
        ['GET /api/invoices' => ['tags' => ['Invoices']]],
        [['name' => 'Invoices']],
    );

    expect(lintDiagnostics(new UndocumentedTagLint($on), $document))->toBe([]);
});

it('degrades on a declarations list nothing usable can be read out of', function (mixed $tags) use ($on): void {
    $document = ['paths' => ['/api/reports' => ['get' => ['tags' => ['Reports']]]], 'tags' => $tags];

    expect(lintDiagnostics(new UndocumentedTagLint($on), $document))->toBe([]);
})->with([
    'not a list' => ['Invoices'],
    'entries that are not maps' => [['Invoices']],
    'maps with no name' => [[['description' => 'Billing documents.']]],
    'a non-string name' => [[['name' => 42]]],
    'an empty name' => [[['name' => '']]],
]);

it('ignores anything in an operation tags member that is not a usable name', function (mixed $tags) use ($on): void {
    $document = lintDocument(['GET /api/reports' => ['tags' => $tags]], [['name' => 'Invoices']]);

    expect(lintDiagnostics(new UndocumentedTagLint($on), $document))->toBe([]);
})->with([
    'absent' => [null],
    'not a list' => ['Reports'],
    'non-string entries' => [[42, ['a']]],
    'empty entries' => [['']],
]);

it('is off unless it is turned on', function (): void {
    $document = lintDocument(['GET /api/reports' => ['tags' => ['Reports']]], [['name' => 'Invoices']]);

    expect(lintDiagnostics(new UndocumentedTagLint, $document))->toBe([])
        ->and(lintDiagnostics(new UndocumentedTagLint(new LintRuleOptions(enabled: false)), $document))->toBe([]);
});

it('silences a tag via the safelist', function (): void {
    $document = lintDocument(['GET /api/reports' => ['tags' => ['Reports']]], [['name' => 'Invoices']]);

    expect(lintDiagnostics(new UndocumentedTagLint(new LintRuleOptions(enabled: true, allow: ['Reports'])), $document))->toBe([]);
});

it('reports undeclared tags in name order whatever order the routes arrive in', function () use ($on): void {
    $messages = static fn (array $operations): array => array_map(
        static fn (object $d): string => $d->message,
        lintDiagnostics(new UndocumentedTagLint($on), lintDocument($operations, [['name' => 'Invoices']])),
    );

    $forwards = ['GET /api/z' => ['tags' => ['Zebra']], 'GET /api/a' => ['tags' => ['Alpha']]];
    $backwards = ['GET /api/a' => ['tags' => ['Alpha']], 'GET /api/z' => ['tags' => ['Zebra']]];

    expect($messages($forwards))->toBe($messages($backwards))
        ->and($messages($forwards)[0])->toContain('"Alpha"')
        ->and($messages($forwards)[1])->toContain('"Zebra"');
});

it('sees a tag only a webhook carries, and names the webhook as the example', function () use ($on): void {
    $document = lintDocument(
        ['GET /api/invoices' => ['tags' => ['Invoices']]],
        [['name' => 'Invoices', 'description' => 'Billing documents.']],
        ['POST form.submitted' => ['tags' => ['Forms']]],
    );

    $findings = lintDiagnostics(new UndocumentedTagLint($on), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"Forms"')
        ->and($findings[0]->message)->toContain('POST webhooks.form.submitted');
});

it('names a route ahead of a webhook when both carry the undeclared tag', function () use ($on): void {
    $document = lintDocument(
        ['GET /api/forms' => ['tags' => ['Forms']]],
        [['name' => 'Invoices']],
        ['POST form.submitted' => ['tags' => ['Forms']]],
    );

    expect(lintDiagnostics(new UndocumentedTagLint($on), $document)[0]->message)->toContain('GET /api/forms');
});
