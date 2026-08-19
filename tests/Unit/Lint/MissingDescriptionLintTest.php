<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Lint\LintRuleOptions;
use Docuccino\Core\Lint\MissingDescriptionLint;

/**
 * The prose-coverage lint: which member counts as prose, the off-switch (which is the default), the
 * safelist by signature and by operationId, the provenance source the message carries, and the
 * deterministic order findings arrive in.
 */
$on = new LintRuleOptions(enabled: true);

it('warns on an operation with neither summary nor description', function () use ($on): void {
    $findings = lintDiagnostics(new MissingDescriptionLint($on), lintDocument(['GET /api/ping' => []]));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('lint.missing-description')
        ->and($findings[0]->message)->toContain('GET /api/ping')
        ->and($findings[0]->help)->toContain('lint.descriptions.allow');
});

it('stays quiet when either member carries prose', function (array $operation) use ($on): void {
    expect(lintDiagnostics(new MissingDescriptionLint($on), lintDocument(['GET /api/ping' => $operation])))->toBe([]);
})->with([
    'summary' => [['summary' => 'Ping.']],
    'description' => [['description' => 'Answers pong.']],
    'both' => [['summary' => 'Ping.', 'description' => 'Answers pong.']],
    // A route whose build failed already carries the fallback description, and its own diagnostic.
    'build-failure fallback' => [['description' => 'Documentation could not be generated for this route.']],
]);

it('treats a blank or non-string member as no prose', function (mixed $summary) use ($on): void {
    expect(lintDiagnostics(new MissingDescriptionLint($on), lintDocument(['GET /api/ping' => ['summary' => $summary]])))->toHaveCount(1);
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'null' => [null],
    'number' => [42],
    'array' => [['a']],
]);

it('is off unless it is turned on', function (): void {
    expect(lintDiagnostics(new MissingDescriptionLint, lintDocument(['GET /api/ping' => []])))->toBe([])
        ->and(lintDiagnostics(new MissingDescriptionLint(new LintRuleOptions(enabled: false)), lintDocument(['GET /api/ping' => []])))->toBe([]);
});

it('silences a finding by signature and by operationId', function (string $allow): void {
    $document = lintDocument(['GET /api/ping' => ['operationId' => 'ping.show']]);

    expect(lintDiagnostics(new MissingDescriptionLint(new LintRuleOptions(enabled: true, allow: [$allow])), $document))->toBe([]);
})->with([
    'by signature' => ['GET /api/ping'],
    'by operationId' => ['ping.show'],
]);

it('carries the source the provenance trail recorded, and none when nothing traced it', function () use ($on): void {
    $document = lintDocument([
        'GET /api/traced' => ['x-docuccino' => ['provenance' => [
            ['producer' => 'fallback', 'fields' => ['tags']],
            ['producer' => 'inference', 'source' => ['file' => 'app/Http/Controllers/PingController.php', 'line' => 17, 'symbol' => 'PingController::show']],
        ]]],
        'GET /api/untraced' => ['x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa']],
    ]);

    $findings = lintDiagnostics(new MissingDescriptionLint($on), $document);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->source?->file)->toBe('app/Http/Controllers/PingController.php')
        ->and($findings[0]->source?->line)->toBe(17)
        ->and($findings[1]->source)->toBeNull();
});

it('reports in signature order whatever order the paths arrive in', function () use ($on): void {
    $messages = static fn (array $paths): array => array_map(
        static fn (object $d): string => $d->message,
        lintDiagnostics(new MissingDescriptionLint($on), ['paths' => $paths]),
    );

    $forwards = ['/api/a' => ['get' => [], 'post' => []], '/api/b' => ['get' => []]];
    $backwards = ['/api/b' => ['get' => []], '/api/a' => ['post' => [], 'get' => []]];

    expect($messages($forwards))->toBe($messages($backwards))
        ->and($messages($forwards)[0])->toContain('GET /api/a')
        ->and($messages($forwards)[1])->toContain('GET /api/b')
        ->and($messages($forwards)[2])->toContain('POST /api/a');
});

it('adding an unrelated operation leaves the others reported identically', function () use ($on): void {
    $before = lintDiagnostics(new MissingDescriptionLint($on), lintDocument(['GET /api/a' => [], 'GET /api/c' => []]));
    $after = lintDiagnostics(new MissingDescriptionLint($on), lintDocument(['GET /api/a' => [], 'GET /api/b' => [], 'GET /api/c' => []]));

    expect(array_map(static fn (object $d): string => $d->message, $after))
        ->toBe([$before[0]->message, 'GET /api/b publishes neither a summary nor a description, so the document never says what it does.', $before[1]->message]);
});

it('warns on a webhook with no prose, and points at the class rather than an action', function () use ($on): void {
    $document = lintDocument([], webhooks: ['POST invoice.paid' => []]);

    $findings = lintDiagnostics(new MissingDescriptionLint($on), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('lint.missing-description')
        ->and($findings[0]->message)->toContain('POST webhooks.invoice.paid')
        ->and($findings[0]->help)->toContain('webhook class')
        ->and($findings[0]->help)->toContain('lint.descriptions.allow');
});

it('stays quiet on a webhook that carries prose', function () use ($on): void {
    $document = lintDocument([], webhooks: ['POST invoice.paid' => ['summary' => 'An invoice was paid.']]);

    expect(lintDiagnostics(new MissingDescriptionLint($on), $document))->toBe([]);
});
