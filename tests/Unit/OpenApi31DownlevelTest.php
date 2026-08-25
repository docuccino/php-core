<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Tests\Support\OpenApiMemberDelta;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

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
