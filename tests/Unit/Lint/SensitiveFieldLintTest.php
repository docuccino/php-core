<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Core\Lint\SensitiveFieldLintOptions;

/**
 * The data-leakage lint is core + framework-agnostic. Dataset coverage over EVERY heuristic entry,
 * plus the non-sensitive no-match contract, the per-property safelist (name + pointer), the
 * off-switch, and table extensibility — the binding coverage standard for a mapping table.
 */
function lintFindings(array $document, ?SensitiveFieldLintOptions $options = null): array
{
    $collector = new DiagnosticCollector;
    $context = new DocumentContext(new DocumentConfig(key: 'd', info: ['title' => 'T', 'version' => '1']), 'doc:d', $collector);

    (new SensitiveFieldLint($options ?? new SensitiveFieldLintOptions))->transform(new UirDocumentDraft($document), $context);

    return $collector->all();
}

function schemaWith(string $property): array
{
    return ['components' => ['schemas' => ['Model' => ['type' => 'object', 'properties' => [$property => ['type' => 'string']]]]]];
}

it('warns on each sensitive property-name shape with its label and pointer', function (string $name, string $label): void {
    $findings = lintFindings(schemaWith($name));

    expect($findings)->toHaveCount(1);
    expect($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('lint.data-leakage')
        ->and($findings[0]->message)->toContain($label)
        ->and($findings[0]->message)->toContain('#/components/schemas/Model/properties/'.$name);
})->with([
    'password' => ['password', 'a password'],
    'passwd' => ['passwd', 'a password'],
    'camel password' => ['userPassword', 'a password'],
    'secret' => ['secret', 'a secret'],
    'api_key' => ['api_key', 'an API key'],
    'api_secret' => ['api_secret', 'an API secret'],
    'client_secret' => ['client_secret', 'a client secret'],
    'private_key' => ['private_key', 'a private key'],
    'access_token' => ['access_token', 'an access token'],
    'refresh_token' => ['refresh_token', 'a refresh token'],
    'remember_token' => ['remember_token', 'a remember-me token'],
    'token' => ['token', 'a token'],
    'internal_id' => ['internal_id', 'an internal identifier'],
    'ssn' => ['ssn', 'a social-security number'],
    'credit_card' => ['credit_card', 'a credit-card number'],
    'card_number' => ['card_number', 'a card number'],
    'cvv' => ['cvv', 'a card verification value'],
]);

it('does not warn on ordinary property names', function (string $name): void {
    expect(lintFindings(schemaWith($name)))->toBe([]);
})->with(['id', 'name', 'email', 'title', 'status', 'created_at', 'internal']);

it('silences a property by name and by JSON pointer via the safelist', function (string $allowEntry): void {
    $options = new SensitiveFieldLintOptions(allow: [$allowEntry]);

    expect(lintFindings(schemaWith('password'), $options))->toBe([]);
})->with([
    'by name' => ['password'],
    'by pointer' => ['#/components/schemas/Model/properties/password'],
]);

it('reports nothing when disabled', function (): void {
    expect(lintFindings(schemaWith('password'), new SensitiveFieldLintOptions(enabled: false)))->toBe([]);
});

it('honours an extended heuristics table', function (): void {
    $options = (new SensitiveFieldLintOptions)->withPatterns(['pincode' => 'a PIN']);

    $findings = lintFindings(schemaWith('pin_code'), $options);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('a PIN');
});

it('scans inline schemas, not only components', function (): void {
    $document = ['paths' => ['/x' => ['post' => ['requestBody' => ['content' => ['application/json' => [
        'schema' => ['type' => 'object', 'properties' => ['api_key' => ['type' => 'string']]],
    ]]]]]]];

    $findings = lintFindings($document);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('an API key');
});
