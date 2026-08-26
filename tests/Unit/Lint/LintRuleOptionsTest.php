<?php

declare(strict_types=1);

use Docuccino\Core\Lint\LintRuleOptions;

/**
 * The safelist every `lint.*.allow` reads. The fact worth pinning is that a pointer may be spelled
 * either way round: the bare RFC 6901 form a finding's message prints, or the `#/…` fragment form
 * every `$ref` in the emitted document uses — an author copying a pointer has seen both.
 */
it('silences a subject named in the safelist and nothing else', function (): void {
    $options = new LintRuleOptions(allow: ['GET /api/ping']);

    expect($options->silences('GET /api/ping'))->toBeTrue()
        ->and($options->silences(null, 'GET /api/ping'))->toBeTrue()
        ->and($options->silences('GET /api/pong'))->toBeFalse()
        ->and($options->silences(null, null))->toBeFalse()
        ->and((new LintRuleOptions)->silences('GET /api/ping'))->toBeFalse();
});

it('reads a pointer safelisted in either form against a subject in either form', function (string $entry, string $subject): void {
    expect((new LintRuleOptions(allow: [$entry]))->silences($subject))->toBeTrue();
})->with([
    'bare entry, bare subject' => ['/components/schemas/Invoice/properties/status/example', '/components/schemas/Invoice/properties/status/example'],
    'fragment entry, bare subject' => ['#/components/schemas/Invoice/properties/status/example', '/components/schemas/Invoice/properties/status/example'],
    'bare entry, fragment subject' => ['/components/schemas/Invoice/properties/status/example', '#/components/schemas/Invoice/properties/status/example'],
    'fragment entry, fragment subject' => ['#/components/schemas/Invoice/properties/status/example', '#/components/schemas/Invoice/properties/status/example'],
]);

it('still reads an unrelated pointer as unrelated in either form', function (string $entry, string $subject): void {
    expect((new LintRuleOptions(allow: [$entry]))->silences($subject))->toBeFalse();
})->with([
    'a neighbouring pointer' => ['#/components/schemas/Invoice/properties/status/example', '/components/schemas/Invoice/properties/statuses/example'],
    'a prefix of the entry' => ['#/components/schemas/Invoice/properties/status/example', '/components/schemas/Invoice/properties/status'],
    'the fragment marker alone' => ['#/components/schemas/Invoice/properties/status/example', '#/'],
]);

it('leaves a label subject alone, which never spells a pointer', function (): void {
    // `silences()` takes several subjects and only one of them is ever a pointer; the label must keep
    // matching itself, and a `#` in front of it must not turn into a match on the bare text.
    $label = 'GET /api/widgets → 200 application/json';

    expect((new LintRuleOptions(allow: [$label]))->silences(null, $label))->toBeTrue()
        ->and((new LintRuleOptions(allow: ['#'.$label]))->silences(null, $label))->toBeFalse()
        ->and((new LintRuleOptions(allow: [$label]))->silences(null, 'GET /api/widgets → 404 application/json'))->toBeFalse();
});
