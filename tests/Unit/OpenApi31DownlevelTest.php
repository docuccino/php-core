<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Tests\Support\EmittedReferences;
use Docuccino\Core\Tests\Support\OpenApiMemberDelta;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;
use Docuccino\Core\Tests\Support\OpenApiValueDelta;

/**
 * Every member OpenAPI 3.2 added, and how this emitter accounts for it. The KEYS are checked against the
 * set derived from the vendored meta-schemas ({@see OpenApiMemberDelta}) in both directions, so a member
 * 3.2 defines with no line here fails — which is the hole this table exists to close. The three
 * `legal-in` entries are members the derivation reports and the emitter must NOT drop, each with the
 * reason it is not a loss.
 *
 * @return array<string, string>
 */
function accountedFor32Members(): array
{
    return [
        // Dropped under one shared code: the remedy is identical for every one of them.
        'components.mediaTypes' => 'downlevel.member-not-in-3.1',
        'encoding.encoding' => 'downlevel.member-not-in-3.1',
        'encoding.itemEncoding' => 'downlevel.member-not-in-3.1',
        'encoding.prefixEncoding' => 'downlevel.member-not-in-3.1',
        'example.dataValue' => 'downlevel.member-not-in-3.1',
        'example.serializedValue' => 'downlevel.member-not-in-3.1',
        'media-type.description' => 'downlevel.member-not-in-3.1',
        'media-type.itemEncoding' => 'downlevel.member-not-in-3.1',
        'media-type.itemSchema' => 'downlevel.member-not-in-3.1',
        'media-type.prefixEncoding' => 'downlevel.member-not-in-3.1',
        'oauth-flows.deviceAuthorization' => 'downlevel.member-not-in-3.1',
        'response.summary' => 'downlevel.member-not-in-3.1',
        'security-scheme.deprecated' => 'downlevel.member-not-in-3.1',
        'security-scheme.oauth2MetadataUrl' => 'downlevel.member-not-in-3.1',
        'server.name' => 'downlevel.member-not-in-3.1',

        // Dropped under a code of its own, because each of these has its own remedy to state.
        'path-item.query' => 'downlevel.query-method',
        'path-item.additionalOperations' => 'downlevel.additional-operations',
        'tag.summary' => 'downlevel.tag-summary',
        'tag.parent' => 'downlevel.tag-parent',
        'tag.kind' => 'downlevel.tag-kind',

        // NOT dropped. `header.allowReserved` is the one that would be a real loss: OpenAPI 3.0's Header
        // Object declares it outright, and 3.0 chains through this emitter, so dropping it here would take
        // a member out of the 3.0 document that 3.0's own schema defines.
        'header.allowReserved' => 'legal-in-3.0-header',
        // The 3.1 schema names a Link Object's server member `body`, which is an upstream typo — 3.1's
        // prose and 3.0's schema both call it `server`, so it is no 3.2 addition.
        'link.server' => 'legal-in-3.1-prose',
        // 3.1 reaches a shared path item through `path-item-or-reference`, so a `$ref` here is 3.1's too;
        // 3.0 has no bucket for one and the 3.0 emitter inlines it (`downlevel.component-path-items`).
        'path-item.$ref' => 'legal-in-3.1',
    ];
}

/**
 * Every VALUE 3.2 added to a member's domain, and how this emitter answers it. The KEYS are checked
 * against the set derived from the vendored meta-schemas ({@see OpenApiValueDelta}) in both directions, so
 * a value 3.2 widened a domain with and nothing here answers fails — which is the hole `querystring` came
 * through: the member axis cannot see it, because `in` is declared by every version and only the value is
 * 3.2's.
 *
 * @return array<string, string>
 */
function accountedFor32Values(): array
{
    return [
        // The value is the parameter's whole reason to exist — the raw query string as ONE value, taking
        // `content` rather than `schema` — so the parameter goes with it.
        'parameter.in.querystring' => 'downlevel.value-not-in-3.1',
        // Only the member has no 3.1 spelling: `form` is the one cookie style 3.1 knows, and it is also
        // 3.1's default there, so the parameter stands — and stands alone, since both styles default
        // `explode` to true and no `explode` reproduces RFC 6265 escaping regardless.
        'parameter.style.cookie' => 'downlevel.value-not-in-3.1',
    ];
}

/**
 * A 3.2 document carrying a `querystring` parameter at every position one can stand — a path item's own
 * list, an operation's list, the shared bucket, a `$ref` naming it, and a second `$ref` reaching it through
 * another name — plus the cookie `style` 3.2 added over both a primitive and an object, and the query and
 * cookie parameters 3.1 spells the same way as the control.
 *
 * Inline rather than a fixture FILE: the UIR schema's own `in` enum stops at 3.1's four locations, so a
 * file carrying one would be a UIR document the product is right to refuse, and the corpus-wide guards
 * would fail on it rather than on the emitter. An emitter's contract is over the document it is HANDED.
 *
 * At most one `querystring` parameter per effective list and never beside a `query` one, since 3.2 forbids
 * both: a subject illegal at the version it is emitted as would measure the wrong thing.
 *
 * @return array<string, mixed>
 */
function documentWith32OnlyValues(): array
{
    $raw = static fn (string $name): array => [
        'name' => $name,
        'in' => 'querystring',
        'content' => ['application/json' => ['schema' => ['type' => 'string']]],
    ];

    $ok = static fn (string $reference): array => [
        'operationId' => 'op.'.$reference,
        'parameters' => [['$ref' => '#/components/parameters/'.$reference]],
        'responses' => ['200' => ['description' => 'Matching tickets.']],
    ];

    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [
            // A path item's own list, which the drop empties.
            '/search' => [
                'parameters' => [$raw('search')],
                'get' => ['operationId' => 'search.index', 'responses' => ['200' => ['description' => 'Results.']]],
            ],
            // An operation's list, with the 3.2 cookie style standing beside it.
            '/events' => [
                'get' => [
                    'operationId' => 'events.index',
                    'parameters' => [
                        $raw('events'),
                        ['name' => 'session', 'in' => 'cookie', 'style' => 'cookie', 'schema' => ['type' => 'string']],
                        // And once over an object, the only shape `explode` is not inert on: this is the
                        // subject that would catch the drop minting an `explode` beside the fallback style.
                        ['name' => 'prefs', 'in' => 'cookie', 'style' => 'cookie', 'schema' => [
                            'type' => 'object',
                            'properties' => ['theme' => ['type' => 'string']],
                        ]],
                    ],
                    'responses' => ['200' => ['description' => 'Events.']],
                ],
            ],
            // A `$ref` naming the shared parameter, and one reaching it through another name.
            '/tickets' => ['get' => $ok('RawQuery'), 'post' => $ok('AliasedQuery')],
            // The control: everything here is 3.1's too, and must come through untouched.
            '/pages' => ['get' => [
                'operationId' => 'pages.index',
                'parameters' => [
                    ['$ref' => '#/components/parameters/Page'],
                    ['name' => 'tz', 'in' => 'cookie', 'style' => 'form', 'schema' => ['type' => 'string']],
                ],
                'responses' => ['200' => ['description' => 'Pages.']],
            ]],
        ],
        'components' => ['parameters' => [
            'RawQuery' => $raw('q'),
            'AliasedQuery' => ['$ref' => '#/components/parameters/RawQuery'],
            'Page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ]],
    ];
}

/**
 * A 3.2 document exercising every 3.2-only construct the downlevel emitter must drop: both path-item
 * ones, and the three Tag Object members.
 *
 * @return array<string, mixed>
 */
function documentWith32OnlyConstructs(): array
{
    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'jsonSchemaDialect' => 'https://spec.openapis.org/oas/3.2/dialect/base',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'tags' => [
            ['name' => 'Billing', 'kind' => 'nav', 'summary' => 'Billing'],
            ['name' => 'Invoices', 'description' => 'Bills.', 'parent' => 'Billing', 'kind' => 'nav'],
        ],
        'paths' => [
            '/search' => [
                'get' => ['operationId' => 'search.get', 'responses' => ['200' => ['description' => 'ok']]],
                'query' => ['operationId' => 'search.query', 'responses' => ['200' => ['description' => 'ok']]],
                'additionalOperations' => [
                    'PURGE' => ['operationId' => 'search.purge', 'responses' => ['204' => ['description' => 'gone']]],
                ],
            ],
        ],
    ];
}

beforeEach(function (): void {
    $this->emitter = new OpenApi31DownlevelEmitter;
});

it('sets the openapi version to 3.1.1 and rewrites the JSON Schema dialect', function (): void {
    $json = $this->emitter->emit(UirDocument::fromArray(documentWith32OnlyConstructs()));

    expect($json)->toContain('"openapi": "3.1.1"');
    expect($json)->toContain('https://spec.openapis.org/oas/3.1/dialect/base');
    expect($json)->not->toContain('3.2/dialect');
});

it('drops the 3.2-only query method with a warning', function (): void {
    $result = $this->emitter->emitWithReport(UirDocument::fromArray(documentWith32OnlyConstructs()));

    expect($result->output)->not->toContain('search.query');

    $warnings = $result->report->warnings();
    $codes = array_map(static fn ($d) => $d->code, $warnings);
    expect($codes)->toContain('downlevel.query-method');
});

it('drops the 3.2-only additionalOperations member with a warning', function (): void {
    $result = $this->emitter->emitWithReport(UirDocument::fromArray(documentWith32OnlyConstructs()));

    expect($result->output)->not->toContain('search.purge');

    $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
    expect($codes)->toContain('downlevel.additional-operations');
});

it('drops each 3.2-only tag member with its own warning', function (string $member, string $code): void {
    $result = $this->emitter->emitWithReport(UirDocument::fromArray(documentWith32OnlyConstructs()));

    expect($result->output)->not->toContain('"'.$member.'"');

    $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
    expect($codes)->toContain($code);
})->with([
    'summary' => ['summary', 'downlevel.tag-summary'],
    'parent' => ['parent', 'downlevel.tag-parent'],
    'kind' => ['kind', 'downlevel.tag-kind'],
]);

// What a dropped `parent` actually costs depends on the document in hand, so the help is read off it
// rather than assumed: an artifact written before anything projected the groups carries `parent`
// alone, and a help claiming the hierarchy survived would be false for it.
it('tells the truth about the dropped parent for the document in hand', function (bool $withGroups, string $expected): void {
    $document = documentWith32OnlyConstructs();
    if ($withGroups) {
        $document['x-tagGroups'] = [['name' => 'Billing', 'tags' => ['Billing', 'Invoices']]];
    }

    $result = $this->emitter->emitWithReport(UirDocument::fromArray($document));
    $parent = array_values(array_filter(
        $result->report->warnings(),
        static fn ($d) => $d->code === 'downlevel.tag-parent',
    ));

    expect($parent)->toHaveCount(1)
        ->and($parent[0]->help)->toContain($expected);
})->with([
    'the groups carry the forest, so only the member is lost' => [true, 'Only the 3.2 `parent` member is dropped'],
    'nothing else carries it, so the hierarchy really flattens' => [false, 'The tag hierarchy flattens'],
]);

it('warns once per tag per dropped member and names the tag', function (): void {
    $result = $this->emitter->emitWithReport(UirDocument::fromArray(documentWith32OnlyConstructs()));

    $tagWarnings = array_values(array_filter(
        $result->report->warnings(),
        static fn ($d) => str_starts_with($d->code, 'downlevel.tag-'),
    ));

    // Billing carries summary+kind, Invoices parent+kind.
    expect(array_map(static fn ($d) => $d->code, $tagWarnings))
        ->toBe(['downlevel.tag-summary', 'downlevel.tag-kind', 'downlevel.tag-parent', 'downlevel.tag-kind'])
        ->and($tagWarnings[0]->message)->toContain('`Billing`')
        ->and($tagWarnings[2]->message)->toContain('`Invoices`');
});

it('keeps the 3.1-valid tag members through the downlevel', function (): void {
    $json = $this->emitter->emit(UirDocument::fromArray(documentWith32OnlyConstructs()));

    expect($json)->toContain('"Invoices"')->toContain('Bills.');
});

it('keeps standard operations untouched through the downlevel', function (): void {
    $json = $this->emitter->emit(UirDocument::fromArray(documentWith32OnlyConstructs()));

    expect($json)->toContain('search.get');
});

it('produces no warnings for a document with no 3.2-only constructs', function (): void {
    $result = $this->emitter->emitWithReport(UirDocument::fromArray(workedExample()));

    expect($result->report->isEmpty())->toBeTrue();
});

it('emits deterministic 3.1 output including in YAML', function (): void {
    $document = UirDocument::fromArray(documentWith32OnlyConstructs());

    expect($this->emitter->emit($document))->toBe($this->emitter->emit($document));

    $yaml = $this->emitter->emit($document, (new EmitOptions)->withYaml());
    expect($yaml)->toContain('openapi: 3.1.1');
    expect($yaml)->toBe($this->emitter->emit($document, (new EmitOptions)->withYaml()));
});

it('projects a mock hint onto the configured faker member, the same as 3.2 does', function (): void {
    // Nothing about a hint is 3.2-only — it leaves as an `x-` extension, which every OAS version takes
    // — so the downlevel has no reason to drop it and no reason to warn.
    $document = UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => [
            'type' => 'object',
            'properties' => ['email' => ['type' => 'string', 'x-docuccino' => ['mock' => ['faker' => 'safeEmail']]]],
        ]]],
    ]);

    $result = $this->emitter->emitWithReport($document, (new EmitOptions)->withMockFakerKey('x-faker'));
    $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['components']['schemas']['S']['properties']['email'])->toBe(['type' => 'string', 'x-faker' => 'safeEmail'])
        ->and($result->report->diagnostics)->toBe([]);
});

/*
 * The sweep. The four Media Type members were the report; the class was every member 3.2 added, and both
 * downlevels published all of them — valid-looking at 3.1, which the oracle cannot see because the 3.1
 * schema closes with `unevaluatedProperties: false` and the oracle disables that check, and outright
 * invalid at 3.0, which closes with `additionalProperties: false` and IS seen. The corpus simply had no
 * fixture carrying one, so `Fixtures/oas32-only-members.uir.json` now does.
 */
it('accounts for every member 3.2 added, as the meta-schemas define that set', function (): void {
    $derived = [];

    foreach (OpenApiMemberDelta::added32() as $object => $members) {
        foreach ($members as $member) {
            $derived[] = $object.'.'.$member;
        }
    }

    $accounted = array_keys(accountedFor32Members());

    sort($derived);
    sort($accounted);

    // The plausible minimum, so a derivation that stopped reading the schemas fails rather than agreeing
    // with an empty table.
    expect(count($derived))->toBeGreaterThanOrEqual(20, 'members 3.2 adds, per the vendored schemas')
        ->and($accounted)->toBe($derived, 'every member 3.2 added is accounted for, and nothing else is');
});

it('drops every 3.2-only member from the fixture that carries them all', function (): void {
    $document = UirDocument::fromArray((array) json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/oas32-only-members.uir.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    ));

    $shared = array_keys(array_filter(
        accountedFor32Members(),
        static fn (string $how): bool => $how === 'downlevel.member-not-in-3.1',
    ));

    foreach (['openapi-3.1', 'openapi-3.0'] as $format) {
        $result = Formats::emit($format, $document, new EmitOptions);
        $codes = array_map(static fn ($d): string => $d->code, $result->report->diagnostics);
        $messages = implode("\n", array_map(static fn ($d): string => $d->message, $result->report->diagnostics));

        // The oracle is the assertion that matters: at 3.0 every one of these was a hard violation.
        expect(OpenApiMetaSchema::findings($format, json_decode($result->output, flags: JSON_THROW_ON_ERROR)))
            ->toBe([], $format.' meta-schema')
            ->and($codes)->toContain('downlevel.member-not-in-3.1');

        // Each member is named in a message of its own, so the reader is told what left and from where.
        foreach ($shared as $slot) {
            [, $member] = explode('.', $slot, 2);
            expect($messages)->toContain('`'.$member.'`');
        }
    }
});

it('inlines a shared media type rather than dangling the $ref that named it', function (): void {
    // 3.1 keeps no `components.mediaTypes`, so dropping the bucket without inlining would publish a
    // document every validator accepts and every client generator breaks on.
    $document = UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/a' => ['get' => [
            'operationId' => 'a.get',
            'responses' => ['200' => ['description' => 'ok', 'content' => [
                'application/json' => ['$ref' => '#/components/mediaTypes/Thing'],
            ]]],
        ]]],
        'components' => ['mediaTypes' => ['Thing' => ['schema' => ['type' => 'object']]]],
    ]);

    foreach (['openapi-3.1', 'openapi-3.0'] as $format) {
        $decoded = json_decode(Formats::emit($format, $document, new EmitOptions)->output, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['paths']['/a']['get']['responses']['200']['content']['application/json'])
            ->toBe(['schema' => ['type' => 'object']], $format)
            ->and($decoded['components']['mediaTypes'] ?? null)->toBeNull($format);
    }
});

it('leaves an object the drop emptied an object, rather than a list', function (string $pointer, array $document): void {
    // The empty-object invariant, at the two positions the canonicaliser reads generically: an Encoding
    // Object and an OAuth Flows Object. `[]` at either is a document no validator accepts, and it is the
    // drop itself that would produce one.
    $uir = UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        ...$document,
    ]);

    foreach (['openapi-3.1', 'openapi-3.0'] as $format) {
        $output = Formats::emit($format, $uir, new EmitOptions)->output;

        expect(OpenApiMetaSchema::findings($format, json_decode($output, flags: JSON_THROW_ON_ERROR)))
            ->toBe([], $format.' meta-schema')
            ->and($output)->toContain($pointer.': {}');
    }
})->with([
    'an encoding whose only member was 3.2-only' => ['"f"', ['paths' => ['/a' => ['post' => [
        'operationId' => 'a.post',
        'requestBody' => ['content' => ['multipart/form-data' => [
            'schema' => ['type' => 'object'],
            'encoding' => ['f' => ['itemEncoding' => ['contentType' => 'text/csv']]],
        ]]],
        'responses' => ['204' => ['description' => 'none']],
    ]]]]],
    'oauth flows carrying only the device flow' => ['"flows"', ['components' => ['securitySchemes' => ['O' => [
        'type' => 'oauth2',
        'flows' => ['deviceAuthorization' => [
            'deviceAuthorizationUrl' => 'https://x.test/d', 'tokenUrl' => 'https://x.test/t', 'scopes' => [],
        ]],
    ]]]]],
]);

it('drops a mock hint entirely when no faker key is configured', function (): void {
    $document = UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => [
            'type' => 'object',
            'properties' => ['email' => ['type' => 'string', 'x-docuccino' => ['mock' => ['faker' => 'safeEmail']]]],
        ]]],
    ]);

    expect($this->emitter->emit($document))->not->toContain('safeEmail');
});

/*
 * The second axis. #224 closed "a member 3.2 added" and derived the set from the meta-schemas so the table
 * could not go short — and a `querystring` parameter still emitted verbatim into 3.1 and 3.0, because the
 * member (`in`) exists in every version and it is the VALUE that does not. A member-shaped guard is blind
 * to that by construction, so the value domains are derived the same way.
 */
it('accounts for every value 3.2 added, as the meta-schemas define that set', function (): void {
    $derived = [];

    foreach (OpenApiValueDelta::added32() as $slot => $values) {
        foreach ($values as $value) {
            $derived[] = $slot.'.'.$value;
        }
    }

    $accounted = array_keys(accountedFor32Values());

    sort($derived);
    sort($accounted);

    // Two assertions with one answer each. The count pins what 3.2 widened, so a third value cannot arrive
    // unnoticed; the position count is the anti-vacuity floor, so a reader that stopped recognising a
    // declaration shape reports "no additions" AND far fewer positions, and fails here rather than agreeing
    // with an empty table forever.
    expect(count(OpenApiValueDelta::domains32()))->toBeGreaterThanOrEqual(5, 'positions 3.2 pins a value domain at')
        ->and($derived)->toHaveCount(2, 'values 3.2 adds, per the vendored schemas')
        ->and($accounted)->toBe($derived, 'every value 3.2 added is accounted for, and nothing else is');
});

it('keeps every 3.2-only value at 3.2 and answers each below it', function (string $format, bool $kept): void {
    $result = Formats::emit($format, UirDocument::fromArray(documentWith32OnlyValues()), new EmitOptions);
    $decoded = json_decode($result->output, flags: JSON_THROW_ON_ERROR);
    $codes = array_map(static fn ($d): string => $d->code, $result->report->diagnostics);

    expect(OpenApiMetaSchema::findings($format, $decoded))->toBe([], $format.' meta-schema')
        ->and(EmittedReferences::dangling($decoded))->toBe([], $format.' references')
        ->and(str_contains($result->output, 'querystring'))->toBe($kept, $format.' querystring')
        ->and(str_contains($result->output, '"style": "cookie"'))->toBe($kept, $format.' cookie style')
        ->and(in_array('downlevel.value-not-in-3.1', $codes, true))->toBe(! $kept, $format.' diagnostic')
        // The control, at every version: a query parameter and a cookie parameter 3.1 spells the same way.
        ->and($result->output)->toContain('"page"')->toContain('"tz"');
})->with([
    'openapi-3.2' => ['openapi-3.2', true],
    'openapi-3.1' => ['openapi-3.1', false],
    'openapi-3.0' => ['openapi-3.0', false],
]);

it('reports each lost parameter once, at the position that lost it', function (string $format): void {
    $result = Formats::emit($format, UirDocument::fromArray(documentWith32OnlyValues()), new EmitOptions);

    $messages = array_map(
        static fn ($d): string => $d->message,
        array_values(array_filter(
            $result->report->warnings(),
            static fn ($d): bool => $d->code === 'downlevel.value-not-in-3.1',
        )),
    );

    // Six, and the two `$ref` use sites are not among them: a shared parameter is reported where it is
    // DEFINED, the way a dropped security scheme is, rather than again at every operation naming it.
    expect($messages)->toHaveCount(6, $format)
        ->and($messages[0])->toContain('`search`')->toContain('#/paths/~1search/parameters/0')
        ->and($messages[1])->toContain('`events`')->toContain('#/paths/~1events/get/parameters/0')
        ->and($messages[2])->toContain('`style: cookie`')->toContain('#/paths/~1events/get/parameters/1/style')
        ->and($messages[3])->toContain('`style: cookie`')->toContain('#/paths/~1events/get/parameters/2/style')
        ->and($messages[4])->toContain('shared parameter `RawQuery`')->toContain('every `$ref` naming it')
        ->and($messages[5])->toContain('shared parameter `AliasedQuery`')
        ->toContain('resolves through `#/components/parameters/RawQuery`');
})->with(['openapi-3.1', 'openapi-3.0']);

it('takes the parameter member the drop emptied rather than publishing it empty', function (string $format): void {
    $decoded = json_decode(
        Formats::emit($format, UirDocument::fromArray(documentWith32OnlyValues()), new EmitOptions)->output,
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($decoded['paths']['/search'])->not->toHaveKey('parameters', $format)
        ->and($decoded['paths']['/tickets']['get'])->not->toHaveKey('parameters', $format)
        ->and($decoded['paths']['/tickets']['post'])->not->toHaveKey('parameters', $format)
        // The cookie parameters kept their list, minus the style member and the parameter beside them —
        // and minus nothing else and PLUS nothing else. The object one is the assertion that matters:
        // `explode` is where a fallback style could be compensated for, both versions default it to true
        // over `form` and `cookie` alike, and no `explode` value reproduces RFC 6265 escaping, so the
        // honest answer is to write none. An `explode` appearing here is the emitter having guessed.
        ->and($decoded['paths']['/events']['get']['parameters'])
        // Canonical order, not source order — the canonicalizer sorts a parameter list.
        ->toBe([
            ['name' => 'prefs', 'in' => 'cookie', 'schema' => [
                'type' => 'object',
                'properties' => ['theme' => ['type' => 'string']],
            ]],
            ['name' => 'session', 'in' => 'cookie', 'schema' => ['type' => 'string']],
        ], $format)
        ->and(array_keys($decoded['components']['parameters']))->toBe(['Page'], $format);
})->with(['openapi-3.1', 'openapi-3.0']);

it('empties the shared bucket, and the components object with it, rather than leaving either a list', function (string $format): void {
    $document = UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/a' => ['get' => [
            'operationId' => 'a.get',
            'parameters' => [['$ref' => '#/components/parameters/RawQuery']],
            'responses' => ['200' => ['description' => 'ok']],
        ]]],
        'components' => ['parameters' => ['RawQuery' => [
            'name' => 'q',
            'in' => 'querystring',
            'content' => ['application/json' => ['schema' => ['type' => 'string']]],
        ]]],
    ]);

    $output = Formats::emit($format, $document, new EmitOptions)->output;

    expect(OpenApiMetaSchema::findings($format, json_decode($output, flags: JSON_THROW_ON_ERROR)))->toBe([], $format)
        ->and($output)->toContain('"components": {}')->not->toContain('parameters');
})->with(['openapi-3.1', 'openapi-3.0']);

/*
 * The guard executed rather than asserted. Every `toBe([])` above would read the same whether the emitter
 * dropped anything or not, so here is the case the oracle has to refuse: the 3.2 emission, which carries
 * every one of these values, relabelled as the older version and handed to that version's own meta-schema.
 */
it('emits at 3.2 a document the older meta-schemas refuse', function (string $format, string $version): void {
    $document = json_decode(
        Formats::emit('openapi-3.2', UirDocument::fromArray(documentWith32OnlyValues()), new EmitOptions)->output,
        flags: JSON_THROW_ON_ERROR,
    );
    $document->openapi = $version;

    $findings = OpenApiMetaSchema::findings($format, $document);

    expect($findings)->not->toBe([], $format.' accepted a document carrying 3.2-only values')
        ->and(implode("\n", $findings))->toContain('/parameters/0/in');
})->with([
    'openapi-3.1' => ['openapi-3.1', '3.1.0'],
    'openapi-3.0' => ['openapi-3.0', '3.0.4'],
]);

it('ends a shared-parameter $ref cycle rather than following it', function (): void {
    // Nothing 3.1 objects to is anywhere in this document; the pair only proves the chain walk stops. A
    // guard that recognised fewer shapes than the chain it protects would hang here instead.
    $document = UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/a' => ['get' => [
            'operationId' => 'a.get',
            'parameters' => [['$ref' => '#/components/parameters/A']],
            'responses' => ['200' => ['description' => 'ok']],
        ]]],
        'components' => ['parameters' => [
            'A' => ['$ref' => '#/components/parameters/B'],
            'B' => ['$ref' => '#/components/parameters/A'],
        ]],
    ]);

    $result = Formats::emit('openapi-3.1', $document, new EmitOptions);
    $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($decoded['components']['parameters']))->toBe(['A', 'B'])
        ->and($decoded['paths']['/a']['get']['parameters'])->toBe([['$ref' => '#/components/parameters/A']])
        ->and($result->report->diagnostics)->toBe([]);
});
