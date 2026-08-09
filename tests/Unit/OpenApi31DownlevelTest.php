<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;

/**
 * A 3.2 document exercising both 3.2-only path-item constructs the downlevel emitter must drop.
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
