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

// --- Value scan (known credential shapes) -----------------------------------

/**
 * A schema whose published example carries `$value` under an innocent member name — the case a
 * name-only heuristic cannot see.
 */
function schemaWithExample(mixed $value): array
{
    return ['components' => ['schemas' => ['Model' => [
        'type' => 'object',
        'properties' => ['type' => ['type' => 'string']],
        'example' => ['type' => $value],
    ]]]];
}

it('warns on each known credential shape appearing in a published value', function (string $value, string $label): void {
    $findings = lintFindings(schemaWithExample($value));

    expect($findings)->toHaveCount(1);
    expect($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('lint.data-leakage')
        ->and($findings[0]->message)->toContain($label)
        ->and($findings[0]->message)->toContain('#/components/schemas/Model/example/type')
        // The diagnostic must never echo the secret — that just moves it into the build log.
        ->and($findings[0]->message)->not->toContain($value);
})->with([
    // The Stripe samples are assembled rather than written out: a literal of that shape trips
    // GitHub's push protection, which cannot tell a fixture from a live key — and shouldn't try.
    'PEM private key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----", 'a PEM private key'],
    'bare PEM private key' => ['-----BEGIN PRIVATE KEY-----MIIB', 'a PEM private key'],
    'AWS long-term key id' => ['AKIAIOSFODNN7EXAMPLE', 'an AWS access key id'],
    'AWS session key id' => ['ASIAIOSFODNN7EXAMPLE', 'an AWS access key id'],
    'GitHub PAT (classic)' => ['ghp_16C7e42F292c6912E7710c838347Ae178B4a', 'a GitHub token'],
    'GitHub fine-grained PAT' => ['github_pat_11ABCDEFG0abcdefghijkl_mnopqrstuvwxyz', 'a GitHub token'],
    'Stripe live secret key' => ['sk_live_'.str_repeat('A', 24), 'a live Stripe secret key'],
    'Stripe live restricted key' => ['rk_live_'.str_repeat('B', 24), 'a live Stripe secret key'],
    'Slack bot token' => ['xoxb-123456789012-abcdefghijkl', 'a Slack token'],
    'Slack user token' => ['xoxp-123456789012-abcdefghijkl', 'a Slack token'],
    'JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'a JWT'],
    'URL userinfo' => ['https://svc:s3cr3t@db.example.com/reports', 'a URL with embedded credentials'],
]);

it('does not warn on ordinary published values', function (mixed $value): void {
    expect(lintFindings(schemaWithExample($value)))->toBe([]);
})->with([
    'uuid' => ['9b2e4f7c-1d3a-4b5c-8e6f-0a1b2c3d4e5f'],
    'ulid' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'sha256' => ['e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
    'base64 sample payload' => ['aGVsbG8gd29ybGQgdGhpcyBpcyBhIHNhbXBsZSBwYXlsb2Fk'],
    'problem type url' => ['https://httpstatuses.io/403'],
    'url with port' => ['https://api.example.com:8443/v1/forms'],
    'stripe test key' => ['sk_test_'.str_repeat('C', 24)],
    'bearer prose' => ['Bearer <token>'],
    'integer' => [42],
    'null' => [null],
]);

it('scans every published-value member, not only example', function (string $key): void {
    $document = ['components' => ['schemas' => ['Model' => [$key => 'AKIAIOSFODNN7EXAMPLE']]]];

    $findings = lintFindings($document);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('#/components/schemas/Model/'.$key);
})->with(['example', 'const', 'default']);

it('points at the exact leaf inside a nested examples map or enum list', function (): void {
    $document = ['components' => ['schemas' => ['Model' => [
        'examples' => ['first' => ['value' => ['token' => 'AKIAIOSFODNN7EXAMPLE']]],
        'enum' => ['ok', 'xoxb-123456789012-abcdefghijkl'],
    ]]]];

    $pointers = array_map(static fn (object $d): string => (string) $d->message, lintFindings($document));

    expect($pointers)->toHaveCount(2);
    expect($pointers[0])->toContain('#/components/schemas/Model/examples/first/value/token');
    expect($pointers[1])->toContain('#/components/schemas/Model/enum/1');
});

it('silences a leaked value by pointer via the safelist', function (): void {
    $options = new SensitiveFieldLintOptions(allow: ['#/components/schemas/Model/example/type']);

    expect(lintFindings(schemaWithExample('AKIAIOSFODNN7EXAMPLE'), $options))->toBe([]);
});
