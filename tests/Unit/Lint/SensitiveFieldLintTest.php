<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Core\Lint\SensitiveFieldLintOptions;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;

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
        ->and($findings[0]->message)->toContain('/components/schemas/Model/properties/'.$name);
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

it('prints the bare RFC 6901 pointer, the one spelling every message uses', function (): void {
    // `#` is URI-fragment syntax rather than pointer syntax, and nothing ever resolves a safelist entry
    // as a `$ref`, so one fact gets one spelling across every producer of one.
    $name = lintFindings(schemaWith('password'))[0];
    $value = lintFindings(schemaWithExample('AKIAIOSFODNN7EXAMPLE'))[0];

    expect($name->message)->toContain('(/components/schemas/Model/properties/password)')
        ->and($name->message)->not->toContain('#/')
        ->and($value->message)->toContain('at /components/schemas/Model/example/type ')
        ->and($value->message)->not->toContain('#/');
});

it('silences a property by name and by JSON pointer via the safelist', function (string $allowEntry): void {
    $options = new SensitiveFieldLintOptions(allow: [$allowEntry]);

    expect(lintFindings(schemaWith('password'), $options))->toBe([]);
})->with([
    'by name' => ['password'],
    'by pointer' => ['/components/schemas/Model/properties/password'],
    // Every message prints the bare pointer, but a `$ref` in the emitted document spells the same path
    // as a URI fragment, so that is the form an author reaches for as often as not.
    'by pointer written as a fragment' => ['#/components/schemas/Model/properties/password'],
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
        ->and($findings[0]->message)->toContain('/components/schemas/Model/example/type')
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
        ->and($findings[0]->message)->toContain('/components/schemas/Model/'.$key);
})->with(['example', 'const', 'default']);

it('points at the exact leaf inside a nested examples map or enum list', function (): void {
    $document = ['components' => ['schemas' => ['Model' => [
        'examples' => ['first' => ['value' => ['token' => 'AKIAIOSFODNN7EXAMPLE']]],
        'enum' => ['ok', 'xoxb-123456789012-abcdefghijkl'],
    ]]]];

    $pointers = array_map(static fn (object $d): string => (string) $d->message, lintFindings($document));

    expect($pointers)->toHaveCount(2);
    expect($pointers[0])->toContain('/components/schemas/Model/examples/first/value/token');
    expect($pointers[1])->toContain('/components/schemas/Model/enum/1');
});

it('silences a leaked value by pointer via the safelist', function (): void {
    $options = new SensitiveFieldLintOptions(allow: ['/components/schemas/Model/example/type']);

    expect(lintFindings(schemaWithExample('AKIAIOSFODNN7EXAMPLE'), $options))->toBe([]);
});

it('names the hide for each side, since the two are not interchangeable', function (): void {
    // `#[Hidden]` is output-only, so at a request body it is the attribute that provably leaves the
    // finding standing — and a property already carrying it is exactly the shape this lint catches.
    // The help says which attribute removes the field from which side; the value branch names neither,
    // because an example is replaced rather than hidden.
    $name = lintFindings(schemaWith('password'))[0];
    $value = lintFindings(schemaWithExample('-----BEGIN PRIVATE KEY-----MIIB'))[0];

    expect($name->help)->toContain('#[Hidden]')
        ->and($name->help)->toContain('#[HiddenFromRequest]')
        ->and($value->help)->not->toContain('#[Hidden]')
        ->and($value->help)->toContain('placeholder');
});

it('reads a name that IS a heuristic apart from one that merely contains it', function (string $name, ?string $exact, ?string $contains): void {
    $options = new SensitiveFieldLintOptions;

    expect($options->matchExact($name))->toBe($exact)
        ->and($options->match($name))->toBe($contains);
})->with([
    'the token itself' => ['token', 'a token', 'a token'],
    'a spelling of it' => ['API-KEY', 'an API key', 'an API key'],
    'a name containing it' => ['token_count', null, 'a token'],
    'a name that is neither' => ['name', null, null],
    'a name that normalises to nothing' => ['--', null, null],
]);

it('takes an application\'s own heuristic as a name in its own right', function (): void {
    $options = (new SensitiveFieldLintOptions)->withPatterns(['sortcode' => 'a bank sort code']);

    expect($options->matchExact('sort_code'))->toBe('a bank sort code')
        ->and($options->matchExact('sort_code_prefix'))->toBeNull();
});

// --- Parameter names --------------------------------------------------------

/**
 * A document publishing one `query` parameter at the position `$at` names: an operation's own list, the
 * path item's shared list, or `components.parameters`. The three are the same subject in the emitted
 * document, so the lint owes the same answer at each.
 */
function documentWithParameter(string $at, string $name, string $in = 'query'): array
{
    $parameter = ['name' => $name, 'in' => $in, 'schema' => ['type' => 'string']];

    return match ($at) {
        'operation' => ['paths' => ['/x' => ['get' => ['parameters' => [$parameter]]]]],
        'path item' => ['paths' => ['/x' => ['parameters' => [$parameter]]]],
        'component' => ['components' => ['parameters' => ['ApiKey' => $parameter]]],
    };
}

/**
 * Every parameter location the OAS 3.2 meta-schema declares, and the answer the lint owes it: whether a
 * sensitive name there is the defect. Read by the behaviour dataset AND by the guard that holds this
 * list against the meta-schema, so a location the spec grows cannot arrive with no answer at all.
 *
 * @return array<string, array{0: string, 1: bool}>
 */
function parameterLocationExpectations(): array
{
    return [
        // The URL carries the value, and a URL is written down all along the request's path — access
        // logs, proxy logs, browser history, an outbound `Referer`.
        'query' => ['query', true],
        'path' => ['path', true],
        // A header is where a credential is SUPPOSED to travel, and so, most of the time, is a cookie:
        // firing here would fire on every correctly-secured API and take the actionable findings with it.
        'header' => ['header', false],
        'cookie' => ['cookie', false],
        // 3.2's whole-query-string parameter. Its `name` names no field, so there is nothing about the
        // name to read — the values inside it are not members this document spells.
        'querystring' => ['querystring', false],
    ];
}

it('warns on a sensitive parameter name at every position a document publishes one', function (string $at, string $pointer): void {
    $findings = lintFindings(documentWithParameter($at, 'api_key'));

    expect($findings)->toHaveCount(1);
    expect($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('lint.data-leakage')
        ->and($findings[0]->message)->toBe(sprintf(
            'The query parameter "api_key" (%s) looks like an API key and may leak sensitive data.',
            $pointer,
        ));
})->with([
    // The pointer names the `name` member, the way a value finding points at the leaf it read: it is
    // the member the author has to change.
    'an operation' => ['operation', '/paths//x/get/parameters/0/name'],
    'a path item' => ['path item', '/paths//x/parameters/0/name'],
    'components.parameters' => ['component', '/components/parameters/ApiKey/name'],
]);

it('reads a parameter name only where the URL carries the value', function (string $in, bool $warns): void {
    expect(lintFindings(documentWithParameter('operation', 'api_key', $in)))->toHaveCount($warns ? 1 : 0);
})->with(parameterLocationExpectations());

/**
 * The union guard. Two answers over five locations means three the behaviour dataset could simply not
 * mention, and a location with no row is a location the lint decides about in silence — so the list is
 * held against the spec's own enum rather than against itself.
 */
it('answers for every parameter location the OAS meta-schema declares', function (): void {
    $schema = json_decode((string) file_get_contents(OpenApiMetaSchema::path('openapi-3.2')), true);
    $declared = $schema['$defs']['parameter']['properties']['in']['enum'];
    $answered = array_keys(parameterLocationExpectations());

    sort($declared);
    sort($answered);

    expect($declared)->not->toBeEmpty()
        ->and($answered)->toBe($declared);
});

it('leaves an unreadable parameter alone rather than guessing at its position', function (mixed $parameter): void {
    expect(lintFindings(['paths' => ['/x' => ['get' => ['parameters' => [$parameter]]]]]))->toBe([]);
})->with([
    // Which position exposes the value is the whole reason the rule fires, so nothing about a
    // parameter missing one can be widened into a finding.
    'no location' => [['name' => 'api_key']],
    'a location that is not a string' => [['name' => 'api_key', 'in' => ['query']]],
    'a name that is not a string' => [['name' => ['api_key'], 'in' => 'query']],
    'not an object at all' => ['api_key'],
]);

it('reports a $ref parameter once, at the component it names', function (): void {
    $document = [
        'paths' => ['/x' => ['get' => ['parameters' => [['$ref' => '#/components/parameters/ApiKey']]]]],
        'components' => ['parameters' => ['ApiKey' => ['name' => 'api_key', 'in' => 'query']]],
    ];

    // The use site carries no name, so nothing is invented there; the declaration it points at is
    // walked like any other node, which is where the author can act.
    $findings = lintFindings($document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('/components/parameters/ApiKey/name');
});

it('does not warn on ordinary parameter names', function (string $name): void {
    expect(lintFindings(documentWithParameter('operation', $name)))->toBe([]);
})->with([
    // The names the workbench's own thirteen documents publish, which is the population this half of
    // the lint runs over: none of them may fire.
    'page', 'per_page', 'page[size]', 'cursor', 'limit', 'offset', 'sort', 'include',
    'fields[entries]', 'filter[status]', 'dry_run', 'session', 'trace', 'precision', 'form', 'id',
]);

it('silences a parameter by name and by pointer, the way a property is silenced', function (string $allowEntry): void {
    $options = new SensitiveFieldLintOptions(allow: [$allowEntry]);

    expect(lintFindings(documentWithParameter('operation', 'api_key'), $options))->toBe([]);
})->with([
    'by name' => ['api_key'],
    'by pointer' => ['/paths//x/get/parameters/0/name'],
    'by pointer written as a fragment' => ['#/paths//x/get/parameters/0/name'],
]);

it('names the move that would actually remove a parameter finding', function (): void {
    // Neither hide reaches a parameter — a parameter is not a property of anything — so the help says
    // where the value belongs instead, and why the URL is the wrong place for it.
    $help = (string) lintFindings(documentWithParameter('operation', 'api_key'))[0]->help;

    expect($help)->toContain('header')
        ->and($help)->toContain('access logs')
        ->and($help)->toContain('lint.leakage.allow')
        ->and($help)->not->toContain('#[Hidden]');
});
