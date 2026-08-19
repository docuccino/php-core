<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Lint\LintRuleOptions;
use Docuccino\Core\Lint\OperationIdStyle;
use Docuccino\Core\Lint\OperationIdStyleLint;

/**
 * Dataset coverage over EVERY clause in the problem table plus the ids that must never raise one —
 * including both shapes Docuccino's own operationId strategies mint, since a rule that fired on our
 * default output would be the noise it exists to prevent.
 */
it('names every problem an operationId can have', function (string $operationId, string $clause): void {
    expect(OperationIdStyle::problem($operationId))->toBe($clause);
})->with([
    'empty' => ['', 'is empty'],
    'whitespace only' => ['   ', 'is empty'],
    'space' => ['list users', 'carries characters outside letters, digits and the separators . - _ @'],
    'slash' => ['users/index', 'carries characters outside letters, digits and the separators . - _ @'],
    'brace' => ['users.{id}', 'carries characters outside letters, digits and the separators . - _ @'],
    'colon' => ['users:index', 'carries characters outside letters, digits and the separators . - _ @'],
    'non-ascii' => ['facturé', 'carries characters outside letters, digits and the separators . - _ @'],
    'leading digit' => ['2fa.verify', 'starts with a digit'],
]);

it('passes an operationId a generator can name a method after', function (string $operationId): void {
    expect(OperationIdStyle::problem($operationId))->toBeNull();
})->with([
    'route name' => ['users.index'],
    'nested route name' => ['api.v1.users.store'],
    'controller-method' => ['InvoiceController@store'],
    'kebab' => ['list-users'],
    'snake' => ['list_users'],
    'digits inside' => ['users.v2.index'],
]);

it('warns with the id, the signature and the clause', function (): void {
    $document = lintDocument(['GET /api/users' => ['operationId' => 'list users', 'summary' => 'List users.']]);

    $findings = lintDiagnostics(new OperationIdStyleLint, $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('lint.operation-id-style')
        ->and($findings[0]->message)->toContain('"list users"')
        ->and($findings[0]->message)->toContain('GET /api/users')
        ->and($findings[0]->message)->toContain('carries characters outside')
        ->and($findings[0]->help)->toContain('lint.operation_ids.allow');
});

it('is on by default and says nothing about an absent or non-string id', function (mixed $operationId): void {
    $document = lintDocument(['GET /api/users' => $operationId === null ? [] : ['operationId' => $operationId]]);

    expect(lintDiagnostics(new OperationIdStyleLint, $document))->toBe([]);
})->with([
    'absent' => [null],
    'number' => [42],
    'array' => [['a']],
]);

it('turns off with the off-switch', function (): void {
    $document = lintDocument(['GET /api/users' => ['operationId' => 'list users']]);

    expect(lintDiagnostics(new OperationIdStyleLint(new LintRuleOptions(enabled: false)), $document))->toBe([]);
});

it('silences a finding by signature and by operationId', function (string $allow): void {
    $document = lintDocument(['GET /api/users' => ['operationId' => 'list users']]);

    expect(lintDiagnostics(new OperationIdStyleLint(new LintRuleOptions(allow: [$allow])), $document))->toBe([]);
})->with([
    'by signature' => ['GET /api/users'],
    'by operationId' => ['list users'],
]);

it('reports one finding per offending operation, in signature order', function (): void {
    $document = lintDocument([
        'POST /api/z' => ['operationId' => 'z index'],
        'GET /api/a' => ['operationId' => '2fa.verify'],
        'GET /api/ok' => ['operationId' => 'ok.index'],
    ]);

    expect(array_map(static fn (object $d): string => $d->message, lintDiagnostics(new OperationIdStyleLint, $document)))
        ->toHaveCount(2)
        ->and(lintDiagnostics(new OperationIdStyleLint, $document)[0]->message)->toContain('GET /api/a')
        ->and(lintDiagnostics(new OperationIdStyleLint, $document)[1]->message)->toContain('POST /api/z');
});

it('warns on a webhook name a generated client cannot name a method after, and names the lever that renames it', function (): void {
    $document = lintDocument([], webhooks: ['POST 1 form submitted!' => ['operationId' => '1 form submitted!']]);

    $findings = lintDiagnostics(new OperationIdStyleLint, $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('lint.operation-id-style')
        ->and($findings[0]->message)->toContain('POST webhooks.1 form submitted!')
        // #[OperationId] never reaches a webhook, so the help names the attribute that does.
        ->and($findings[0]->help)->toContain('#[Webhook]')
        ->and($findings[0]->help)->not->toContain('#[OperationId]')
        ->and($findings[0]->help)->toContain('lint.operation_ids.allow');
});

it('names #[OperationId] for a route and #[Webhook] for a webhook', function (): void {
    $document = lintDocument(
        ['GET /api/users' => ['operationId' => 'list users']],
        webhooks: ['POST bad name' => ['operationId' => 'bad name']],
    );

    $helps = array_map(static fn (object $d): ?string => $d->help, lintDiagnostics(new OperationIdStyleLint, $document));

    expect($helps)->toHaveCount(2)
        ->and($helps[0])->toContain('#[OperationId]')
        ->and($helps[1])->toContain('#[Webhook]');
});

it('silences a webhook finding by signature and by operationId', function (string $allow): void {
    $document = lintDocument([], webhooks: ['POST bad name' => ['operationId' => 'bad name']]);

    expect(lintDiagnostics(new OperationIdStyleLint(new LintRuleOptions(allow: [$allow])), $document))->toBe([]);
})->with([
    'by signature' => ['POST webhooks.bad name'],
    'by operationId' => ['bad name'],
]);
