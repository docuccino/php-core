<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Validation\Validator;

/**
 * The 3.0 downlevel. It chains off the 3.1 emitter, so the tests here cover only what 3.0 itself
 * restricts: its draft-4-shaped schema dialect and the document members added after 3.0.
 */

/**
 * The `downlevel` fixture: one document carrying every construct the 3.0 emitter converts.
 *
 * @return array<string, mixed>
 */
function downlevelFixture(): array
{
    return loadFixture('downlevel.uir.json');
}

/**
 * Emit a one-schema document and return that schema plus the note codes it raised.
 *
 * @param  array<string, mixed>  $schema
 * @return array{schema: array<string, mixed>, codes: list<string>}
 */
function downlevel30(array $schema): array
{
    $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => $schema]],
    ]));

    $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    return [
        'schema' => $decoded['components']['schemas']['S'],
        'codes' => array_map(static fn ($d) => $d->code, $result->report->diagnostics),
    ];
}

it('emits OpenAPI 3.0 JSON byte-identical to the committed golden', function (): void {
    $document = UirDocument::fromArray(downlevelFixture());

    expect((new OpenApi30DownlevelEmitter)->emit($document))->toBe(loadGolden('downlevel.openapi30.json'));
});

it('keeps the downlevel fixture valid against the bundled UIR schema', function (): void {
    $validation = (new Validator)->validate(downlevelFixture());

    expect($validation->isValid())->toBeTrue()
        ->and($validation->errors)->toBe([]);
});

it('sets the openapi version to 3.0.4 and drops the JSON Schema dialect', function (): void {
    $json = (new OpenApi30DownlevelEmitter)->emit(UirDocument::fromArray(downlevelFixture()));

    expect($json)->toContain('"openapi": "3.0.4"');
    expect($json)->not->toContain('jsonSchemaDialect');
});

it('chains the 3.1 downlevel so 3.2-only constructs are already gone', function (): void {
    $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(kitchenSink()));

    expect($result->output)->not->toContain('widgets.query');

    $codes = array_map(static fn ($d) => $d->code, $result->report->diagnostics);
    expect($codes)->toContain('downlevel.query-method');
});

it('produces no notes for a document with nothing to downlevel', function (): void {
    $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(workedExample()));

    expect($result->report->isEmpty())->toBeTrue();
});

it('emits deterministic 3.0 output including in YAML', function (): void {
    $emitter = new OpenApi30DownlevelEmitter;
    $document = UirDocument::fromArray(downlevelFixture());

    expect($emitter->emit($document))->toBe($emitter->emit($document));

    $yaml = $emitter->emit($document, (new EmitOptions)->withYaml());
    expect($yaml)->toContain('openapi: 3.0.4');
    expect($yaml)->toBe($emitter->emit($document, (new EmitOptions)->withYaml()));
});

it('names the format it produces', function (): void {
    expect((new OpenApi30DownlevelEmitter)->format())->toBe('openapi-3.0');
});

describe('document members 3.0 does not define', function (): void {
    it('drops webhooks with a warning', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));

        expect($result->output)->not->toContain('things.webhook.created');

        $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
        expect($codes)->toContain('downlevel.webhooks');
    });

    it('drops info.summary with a warning', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));

        expect($result->output)->not->toContain('nowhere to put');

        $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
        expect($codes)->toContain('downlevel.info-summary');
    });

    it('drops components.pathItems with a warning', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));

        expect($result->output)->not->toContain('shared.get');

        $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
        expect($codes)->toContain('downlevel.component-path-items');
    });

    it('rewrites an SPDX license identifier as the SPDX url', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['info']['license'])->toBe(['name' => 'MIT', 'url' => 'https://spdx.org/licenses/MIT']);

        $codes = array_map(static fn ($d) => $d->code, $result->report->diagnostics);
        expect($codes)->toContain('downlevel.license-identifier');
    });

    it('drops an SPDX license identifier when a url is already set', function (): void {
        $document = downlevelFixture();
        $document['info']['license'] = ['name' => 'MIT', 'identifier' => 'MIT', 'url' => 'https://example.com/licence'];

        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray($document));
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['info']['license'])->toBe(['name' => 'MIT', 'url' => 'https://example.com/licence']);

        $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
        expect($codes)->toContain('downlevel.license-identifier');
    });

    it('leaves a license with no identifier alone', function (): void {
        $document = downlevelFixture();
        $document['info']['license'] = ['name' => 'MIT', 'url' => 'https://example.com/licence'];

        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray($document));

        $codes = array_map(static fn ($d) => $d->code, $result->report->diagnostics);
        expect($codes)->not->toContain('downlevel.license-identifier');
    });

    it('drops a mutualTLS scheme and every requirement naming it', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['components']['securitySchemes'])->not->toHaveKey('clientCert');
        // The document requirement that named only the dropped scheme is gone; the mixed one keeps
        // its surviving member, and the operation's sole requirement leaves no empty `security`.
        expect($decoded['security'])->toBe([['apiKey' => []]]);
        expect($decoded['paths']['/things']['post'])->not->toHaveKey('security');

        $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
        expect($codes)->toContain('downlevel.mutual-tls');
    });
});

describe('schema dialect conversions', function (): void {
    it('converts every construct 3.0 spells differently', function (array $schema, array $expected, array $codes): void {
        $result = downlevel30($schema);

        expect($result['schema'])->toBe($expected);
        expect($result['codes'])->toBe($codes);
    })->with([
        'nullable type array' => [
            ['type' => ['string', 'null']],
            ['type' => 'string', 'nullable' => true],
            [],
        ],
        'null-only type' => [
            ['type' => 'null'],
            ['nullable' => true],
            ['downlevel.null-type'],
        ],
        'null-only type array' => [
            ['type' => ['null']],
            ['nullable' => true],
            ['downlevel.null-type'],
        ],
        'multi type becomes anyOf' => [
            ['type' => ['string', 'integer']],
            ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            ['downlevel.multi-type'],
        ],
        'nullable multi type becomes a nullable anyOf' => [
            ['type' => ['string', 'integer', 'null']],
            ['anyOf' => [['type' => 'string'], ['type' => 'integer']], 'nullable' => true],
            ['downlevel.multi-type'],
        ],
        'multi type beside a composition is dropped' => [
            ['type' => ['string', 'integer'], 'oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            ['downlevel.multi-type'],
        ],
        'null branch folds a plain branch into the parent' => [
            ['anyOf' => [['type' => 'string', 'maxLength' => 4], ['type' => 'null']]],
            ['type' => 'string', 'maxLength' => 4, 'nullable' => true],
            [],
        ],
        'null branch wraps a $ref branch in allOf' => [
            ['anyOf' => [['$ref' => '#/components/schemas/Other'], ['type' => 'null']]],
            ['allOf' => [['$ref' => '#/components/schemas/Other']], 'nullable' => true],
            [],
        ],
        'null branch beside a real union stays a composition' => [
            ['oneOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'null']]],
            ['oneOf' => [['type' => 'string'], ['type' => 'integer']], 'nullable' => true],
            ['downlevel.nullable-composition'],
        ],
        'const becomes a single-value enum' => [
            ['const' => 'widget'],
            ['enum' => ['widget']],
            ['downlevel.const'],
        ],
        'const beside an enum is dropped' => [
            ['type' => 'boolean', 'const' => true, 'enum' => [true, false]],
            ['type' => 'boolean', 'enum' => [true, false]],
            ['downlevel.const'],
        ],
        'schema examples become the first example' => [
            ['type' => 'integer', 'examples' => [1, 2]],
            ['type' => 'integer', 'example' => 1],
            ['downlevel.schema-examples'],
        ],
        'schema examples are dropped when example is set' => [
            ['type' => 'integer', 'example' => 9, 'examples' => [1, 2]],
            ['type' => 'integer', 'example' => 9],
            ['downlevel.schema-examples'],
        ],
        'numeric exclusive bounds become the boolean form' => [
            ['type' => 'integer', 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 100],
            ['type' => 'integer', 'maximum' => 100, 'exclusiveMaximum' => true, 'minimum' => 0, 'exclusiveMinimum' => true],
            [],
        ],
        'a numeric exclusive bound is dropped when the inclusive one is taken' => [
            ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => 0],
            ['type' => 'integer', 'minimum' => 5],
            ['downlevel.exclusive-bound'],
        ],
        'a boolean exclusive bound passes through' => [
            ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true],
            ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true],
            [],
        ],
        'base64 contentEncoding becomes format byte' => [
            ['type' => 'string', 'contentEncoding' => 'base64'],
            ['type' => 'string', 'format' => 'byte'],
            ['downlevel.content-encoding'],
        ],
        'another contentEncoding is dropped' => [
            ['type' => 'string', 'contentEncoding' => 'quoted-printable'],
            ['type' => 'string'],
            ['downlevel.content-encoding'],
        ],
        'base64 contentEncoding is dropped when format is taken' => [
            ['type' => 'string', 'format' => 'password', 'contentEncoding' => 'base64'],
            ['type' => 'string', 'format' => 'password'],
            ['downlevel.content-encoding'],
        ],
        '$ref siblings hoist into an allOf wrapper' => [
            ['$ref' => '#/components/schemas/Other', 'description' => 'Prose'],
            ['description' => 'Prose', 'allOf' => [['$ref' => '#/components/schemas/Other']]],
            ['downlevel.ref-siblings'],
        ],
        'a bare $ref is left alone' => [
            ['$ref' => '#/components/schemas/Other'],
            ['$ref' => '#/components/schemas/Other'],
            [],
        ],
        '$comment is dropped without a note' => [
            ['type' => 'string', '$comment' => 'an aside'],
            ['type' => 'string'],
            [],
        ],
        'an unknown keyword passes through untouched' => [
            ['type' => 'string', 'x-enumDescriptions' => ['a' => 'A'], 'somethingNew' => 1],
            ['type' => 'string', 'somethingNew' => 1, 'x-enumDescriptions' => ['a' => 'A']],
            [],
        ],
    ]);

    it('drops every keyword 3.0 cannot express, naming it', function (string $keyword): void {
        $result = downlevel30(['type' => 'object', $keyword => ['type' => 'string']]);

        expect($result['schema'])->toBe(['type' => 'object']);
        expect($result['codes'])->toBe(['downlevel.unsupported-keyword']);
    })->with(OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS);

    it('drops every silent keyword without a note', function (string $keyword): void {
        $result = downlevel30(['type' => 'object', $keyword => 'anything']);

        expect($result['schema'])->toBe(['type' => 'object']);
        expect($result['codes'])->toBe([]);
    })->with(OpenApi30DownlevelEmitter::SILENT_SCHEMA_KEYWORDS);

    it('converts subschemas at every nesting position', function (): void {
        $result = downlevel30([
            'type' => 'object',
            'properties' => ['a' => ['type' => ['string', 'null']]],
            'items' => ['type' => ['string', 'null']],
            'not' => ['type' => ['string', 'null']],
            'additionalProperties' => ['type' => ['string', 'null']],
            'allOf' => [['type' => ['string', 'null']]],
        ]);

        $nullable = ['type' => 'string', 'nullable' => true];

        expect($result['schema']['properties']['a'])->toBe($nullable);
        expect($result['schema']['items'])->toBe($nullable);
        expect($result['schema']['not'])->toBe($nullable);
        expect($result['schema']['additionalProperties'])->toBe($nullable);
        expect($result['schema']['allOf'][0])->toBe($nullable);
    });

    it('leaves a boolean additionalProperties alone', function (): void {
        $result = downlevel30(['type' => 'object', 'additionalProperties' => false]);

        expect($result['schema'])->toBe(['type' => 'object', 'additionalProperties' => false]);
    });

    it('never mistakes user example data for a schema position', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        $example = $decoded['paths']['/things']['post']['responses']['201']['content']['application/json']['examples']['one']['value'];
        expect($example['schema'])->toBe('a user value keyed like a schema position');
    });
});

it('emits nothing OpenAPI 3.0 cannot read', function (string $golden): void {
    $document = json_decode(loadGolden($golden), true, flags: JSON_THROW_ON_ERROR);

    $banned = [
        ...OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS,
        ...OpenApi30DownlevelEmitter::SILENT_SCHEMA_KEYWORDS,
        'const',
        'jsonSchemaDialect',
        'webhooks',
    ];

    $found = [];
    $scan = function (mixed $node) use (&$scan, $banned, &$found): void {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (in_array((string) $key, $banned, true)) {
                $found[] = (string) $key;
            }

            // A 2020-12 `type` array is the other thing a 3.0 validator rejects outright.
            if ($key === 'type' && is_array($value)) {
                $found[] = 'type array';
            }

            $scan($value);
        }
    };
    $scan($document);

    expect($found)->toBe([]);
})->with([
    'worked-example' => ['worked-example.openapi30.json'],
    'kitchen-sink' => ['kitchen-sink.openapi30.json'],
    'downlevel' => ['downlevel.openapi30.json'],
]);
