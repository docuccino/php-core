<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Lint\LintRuleOptions;
use Docuccino\Core\Lint\UnpinnedRedirectLint;

/**
 * The unpinned-redirect lint reads the FINISHED document, which is the whole reason it lives here. What
 * it finds there is one of two things: the range alone, or the range beside a code — the shape an
 * overlay leaves behind, since an overlay can add the code but not retract what the build published.
 */
$on = new LintRuleOptions(enabled: true);

/** @param array<string, mixed> $responses */
function redirectDocument(array $responses, string $signature = 'GET /auth/callback'): array
{
    return lintDocument([$signature => ['operationId' => 'auth.callback', 'responses' => $responses]]);
}

it('flags a redirect the document publishes only as a range', function () use ($on): void {
    $findings = lintDiagnostics(new UnpinnedRedirectLint($on), redirectDocument([
        '3XX' => ['description' => 'Redirect', 'headers' => ['Location' => ['schema' => ['type' => 'string']]]],
        '500' => ['$ref' => '#/components/responses/Error500'],
    ]));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Info)
        ->and($findings[0]->code)->toBe('lint.unpinned-redirect')
        ->and($findings[0]->message)->toContain('GET /auth/callback')
        ->and($findings[0]->message)->toContain('3XX')
        ->and($findings[0]->help)->toContain('#[Response(302)]')
        ->and($findings[0]->help)->toContain('lint.unpinned_redirect.allow');
});

it('reports a code published beside the range it should have retired', function (string $status) use ($on): void {
    // The overlay shape: something named the code after the build, so the range is still there and the
    // document now says both. A distinct message, because the fix is to remove the range rather than to
    // name a code that is already named.
    $findings = lintDiagnostics(new UnpinnedRedirectLint($on), redirectDocument([
        '3XX' => ['description' => 'Redirect'],
        $status => ['description' => 'Declared after the document was built'],
    ]));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('lint.unpinned-redirect')
        ->and($findings[0]->message)->toContain('3XX', $status)
        ->and($findings[0]->message)->not->toContain('never says which')
        ->and($findings[0]->help)->toContain('overlay')
        ->and($findings[0]->help)->toContain('lint.unpinned_redirect.allow');
})->with([
    'moved permanently' => ['301'],
    'found' => ['302'],
    'see other' => ['303'],
    'not modified' => ['304'],
    'temporary redirect' => ['307'],
    'permanent redirect' => ['308'],
]);

it('names every code standing beside the range, in a fixed order', function () use ($on): void {
    // A conditional redirect declared in an overlay leaves several; the message is a function of the set
    // rather than of the order the responses happen to sit in.
    $findings = lintDiagnostics(new UnpinnedRedirectLint($on), redirectDocument([
        '307' => ['description' => 'Temporary Redirect'],
        '3XX' => ['description' => 'Redirect'],
        '301' => ['description' => 'Moved Permanently'],
    ]));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('301 and 307');
});

it('stays silent where nothing redirects at all', function (array $responses) use ($on): void {
    expect(lintDiagnostics(new UnpinnedRedirectLint($on), redirectDocument($responses)))->toBe([]);
})->with([
    'a plain success' => [['200' => ['description' => 'OK']]],
    'a retracted range, leaving only the code' => [['302' => ['description' => 'Found']]],
    'another class of range entirely' => [['4XX' => ['description' => 'Client error']]],
    'no responses member' => [[]],
]);

it('reads a concrete status of another class as naming nothing', function () use ($on): void {
    // A declared 200 or 404 says nothing about which redirect the endpoint answers with.
    $findings = lintDiagnostics(new UnpinnedRedirectLint($on), redirectDocument([
        '200' => ['description' => 'OK'],
        '3XX' => ['description' => 'Redirect'],
        '404' => ['description' => 'Not Found'],
    ]));

    expect($findings)->toHaveCount(1);
});

it('finds an unpinned redirect on a webhook too', function () use ($on): void {
    $document = lintDocument([], [], ['POST widget.archived' => ['responses' => ['3XX' => ['description' => 'Redirect']]]]);

    expect(lintDiagnostics(new UnpinnedRedirectLint($on), $document))->toHaveCount(1);
});

it('is silenced by the switch and by either name on the safelist', function (LintRuleOptions $options) use ($on): void {
    $document = redirectDocument(['3XX' => ['description' => 'Redirect']]);

    expect(lintDiagnostics(new UnpinnedRedirectLint($on), $document))->toHaveCount(1)
        ->and(lintDiagnostics(new UnpinnedRedirectLint($options), $document))->toBe([]);
})->with([
    'off' => [new LintRuleOptions(enabled: false)],
    'by signature' => [new LintRuleOptions(allow: ['GET /auth/callback'])],
    'by operationId' => [new LintRuleOptions(allow: ['auth.callback'])],
]);

it('reads a malformed responses member as nothing to say', function (mixed $responses) use ($on): void {
    expect(lintDiagnostics(new UnpinnedRedirectLint($on), lintDocument([
        'GET /auth/callback' => ['responses' => $responses],
    ])))->toBe([]);
})->with([
    'a string' => ['3XX'],
    'null' => [null],
]);
