<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;

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
