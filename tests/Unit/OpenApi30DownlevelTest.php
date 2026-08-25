<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ReportingEmitter;
use Docuccino\Core\SpecValidation\Validator;
use Docuccino\Core\Tests\Support\EmittedDocument;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

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

/**
 * The one assertion in this file that can see a MAP. Everything else here reads the emission with an
 * associative `json_decode`, which answers a PHP array for `{}` and for `[]` alike — so a downlevel that
 * turned a map into a sequence, or dropped an empty one, passed every test on this page. This emitter is
 * where an empty map is most likely to appear, because 3.0 is what strips a keyword down to nothing.
 */
it('downlevels to a 3.0 JSON and YAML that agree on every map, sequence and scalar', function (): void {
    $document = UirDocument::fromArray(downlevelFixture());

    $json = json_decode(Formats::emit('openapi-3.0', $document, new EmitOptions)->output, flags: JSON_THROW_ON_ERROR);
    $yaml = EmittedDocument::parseYaml(Formats::emit('openapi-3.0', $document, (new EmitOptions)->withYaml())->output);

    expect(EmittedDocument::differences($json, $yaml))->toBe([])
        // Anti-vacuity, and the reason this document is the right subject: the downlevel produces empty
        // maps, so the comparison above has the shape it exists to check.
        ->and(EmittedDocument::emptyMaps($json))->not->toBeEmpty()
        ->and(EmittedDocument::nodes($json))->toBeGreaterThan(100);
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
    it('drops webhooks with a warning that names each one', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));

        expect($result->output)->not->toContain('things.webhook.created');

        $dropped = array_values(array_filter($result->report->warnings(), static fn ($d): bool => $d->code === 'downlevel.webhooks'));

        // Counting them would leave the reader knowing something was lost without knowing what: each
        // name is a contract a consumer of the 3.0 artifact can no longer see.
        expect($dropped)->toHaveCount(1)
            ->and($dropped[0]->message)->toContain('thingCreated');
    });

    it('drops info.summary with a warning', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));

        expect($result->output)->not->toContain('nowhere to put');

        $codes = array_map(static fn ($d) => $d->code, $result->report->warnings());
        expect($codes)->toContain('downlevel.info-summary');
    });

    it('drops components.pathItems, inlining what a $ref names', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray(downlevelFixture()));

        // The bucket has no 3.0 home; what it held is still in the document, at the path that named it.
        expect($result->output)->toContain('shared.get');

        $codes = array_map(static fn ($d) => $d->code, $result->report->diagnostics);
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

describe('prose beside a $ref', function (): void {
    /**
     * One document with a `$ref` in all three positions that carry prose: a Reference Object, whose
     * `summary` and `description` are 3.1 fixed fields 3.0 has no answer for, and two Path Items, where
     * both members are 3.0's own.
     *
     * @return array{array<string, mixed>, list<string>}
     */
    $emitted = static function (): array {
        $pathItem = [
            '$ref' => '#/components/pathItems/shared',
            'summary' => 'Things',
            'description' => 'Everything about things',
        ];

        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => [
                '/things' => $pathItem,
                '/other' => ['get' => [
                    'responses' => ['404' => ['$ref' => '#/components/responses/Error404', 'description' => 'Its own words']],
                    'callbacks' => ['onData' => ['{$request.body#/cb}' => $pathItem]],
                ]],
            ],
            'components' => [
                'responses' => ['Error404' => ['description' => 'Not Found']],
                // Defined, so both path items are inlined rather than dropped: what this block is about is
                // the prose beside each `$ref`, and a `$ref` naming nothing would take the prose with it.
                'pathItems' => ['shared' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
            ],
        ]));

        return [
            json_decode($result->output, true, flags: JSON_THROW_ON_ERROR),
            array_map(static fn ($d): string => $d->code, $result->report->diagnostics),
        ];
    };

    it('drops a reference\'s own wording, naming where it stood', function () use ($emitted): void {
        [$decoded, $codes] = $emitted();

        expect($decoded['paths']['/other']['get']['responses']['404'])->toBe(['$ref' => '#/components/responses/Error404'])
            ->and(array_count_values($codes)['downlevel.ref-siblings'] ?? 0)->toBe(1);
    });

    it('keeps the wording a path item states, which 3.0 defines itself', function (string $case, array $pointer) use ($emitted): void {
        // `summary` and `description` are Path Item fixed fields in 3.0 as much as in 3.2, so they are
        // not siblings a 3.0 reader ignores — and dropping them would lose wording 3.0 can carry. One
        // note, not three, is the whole assertion: the drop reaches the reference position and no other.
        [$decoded, $codes] = $emitted();

        $node = $decoded;
        foreach ($pointer as $token) {
            $node = $node[$token];
        }

        expect($node['summary'])->toBe('Things')
            ->and($node['description'])->toBe('Everything about things')
            ->and(array_count_values($codes)['downlevel.ref-siblings'] ?? 0)->toBe(1);
    })->with([
        'a path' => ['a path', ['paths', '/things']],
        'a path item a callback maps' => ['a path item a callback maps', ['paths', '/other', 'get', 'callbacks', 'onData', '{$request.body#/cb}']],
    ]);
});

describe('an operation with no responses', function (): void {
    /**
     * The `responses` member is REQUIRED on a 3.0 Operation Object and its map carries
     * `minProperties: 1`; 3.1 and 3.2 require neither. So the same document is valid emitted as 3.1 or
     * 3.2 and invalid emitted as 3.0 unless the 3.0 pass answers for it.
     *
     * @param  array<string, mixed>  $paths
     * @return array{array<string, mixed>, list<Diagnostic>}
     */
    $emit = static function (array $paths): array {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => $paths,
        ]));

        return [json_decode($result->output, true, flags: JSON_THROW_ON_ERROR), $result->report->diagnostics];
    };

    /** The one description the placeholder publishes, so the assertions below read it from one place. */
    $placeholder = ['default' => ['description' => "This operation's responses are not described, so no status code or body is guaranteed."]];

    it('gives it a default response that describes nothing', function (array $paths) use ($emit, $placeholder): void {
        [$decoded, $diagnostics] = $emit($paths);

        // No status, no media type, no schema: the degraded answer says the document is silent rather
        // than inventing a contract a client would generate against.
        expect($decoded['paths']['/things']['get']['responses'])->toBe($placeholder)
            ->and(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['downlevel.empty-responses']);
    })->with([
        'no responses member' => [['/things' => ['get' => ['operationId' => 'things.index']]]],
        'an empty responses map' => [['/things' => ['get' => ['operationId' => 'things.index', 'responses' => []]]]],
    ]);

    it('names the operation, at its pointer and as a route signature', function () use ($emit): void {
        [, $diagnostics] = $emit(['/things/{thing}' => ['get' => []]]);

        expect($diagnostics)->toHaveCount(1)
            ->and($diagnostics[0]->severity)->toBe(Severity::Info)
            ->and($diagnostics[0]->message)->toContain('#/paths/~1things~1{thing}/get')
            // The signature is what puts the note beside that route's other diagnostics.
            ->and($diagnostics[0]->routeSignature)->toBe('GET /things/{thing}')
            ->and($diagnostics[0]->help)->toContain('#[Response]');
    });

    it('leaves an operation that documents a response alone', function () use ($emit): void {
        [$decoded, $diagnostics] = $emit(['/things' => ['get' => ['responses' => ['204' => ['description' => 'No content']]]]]);

        expect($decoded['paths']['/things']['get']['responses'])->toBe(['204' => ['description' => 'No content']])
            ->and($diagnostics)->toBe([]);
    });

    it('reaches a callback\'s operation, which 3.0 requires just the same', function () use ($emit, $placeholder): void {
        [$decoded, $diagnostics] = $emit(['/things' => ['post' => [
            'responses' => ['202' => ['description' => 'Accepted']],
            'callbacks' => ['onDone' => ['{$request.body#/cb}' => ['post' => ['operationId' => 'things.done']]]],
        ]]]);

        expect($decoded['paths']['/things']['post']['callbacks']['onDone']['{$request.body#/cb}']['post']['responses'])->toBe($placeholder)
            ->and($diagnostics)->toHaveCount(1)
            // A callback's key is a runtime expression, not a route, so there is no signature to claim.
            ->and($diagnostics[0]->routeSignature)->toBeNull()
            ->and($diagnostics[0]->message)->toContain('/callbacks/onDone/');
    });

    it('leaves a path item that is only a $ref alone', function () use ($emit): void {
        // A `$ref` 3.0 keeps — it names no `components.pathItems` member, so there is nothing to inline
        // and nothing here for the placeholder to answer for.
        [$decoded, $diagnostics] = $emit(['/things' => ['$ref' => 'shared.yaml#/paths/~1things']]);

        expect($decoded['paths']['/things'])->toBe(['$ref' => 'shared.yaml#/paths/~1things'])
            ->and($diagnostics)->toBe([]);
    });

    it('changes nothing for 3.1 and 3.2, which accept an operation with none', function (ReportingEmitter $emitter) use ($placeholder): void {
        $document = UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/things' => ['get' => ['operationId' => 'things.index']]],
        ]);

        $result = $emitter->emitWithReport($document, new EmitOptions);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['paths']['/things']['get'])->toBe(['operationId' => 'things.index'])
            ->and($decoded['paths']['/things']['get'])->not->toBe($placeholder)
            ->and(array_map(static fn (Diagnostic $d): string => $d->code, $result->report->diagnostics))
            ->not->toContain('downlevel.empty-responses');
    })->with([
        '3.2' => [new OpenApi32Emitter],
        '3.1' => [new OpenApi31DownlevelEmitter],
    ]);
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

    /*
     * The guard that says which keywords those lists must hold, read off the vendored 3.0 meta-schema
     * rather than off anybody's memory of the spec. 3.0's Schema Object ENUMERATES its members and
     * closes with `additionalProperties: false`, so a keyword absent both from 3.0 and from the drop
     * list fails 3.0's own gate whatever value it carries — which is how `additionalItems` shipped
     * invalid at every value, with the whole suite green.
     *
     * The universe it sweeps is {@see schemaKeywordVocabulary()} — BOTH product tables, not the
     * canonicaliser's order alone. Order named 57 keywords and the drop lists named four it had never
     * heard of, so those four sat outside the sweep entirely: removing one changed what a 3.0 export
     * publishes and nothing failed.
     */
    it('answers for every schema keyword, against what 3.0 actually defines', function (): void {
        $schema = OpenApiMetaSchema::decode('openapi-3.0')->definitions->Schema;

        expect($schema->additionalProperties)->toBeFalse()
            ->and(get_object_vars($schema->patternProperties))->toHaveKey('^x-');

        $defined = array_keys(get_object_vars($schema->properties));

        // Anti-vacuity: a decode that stopped finding the member set would make the rest pass on nothing.
        expect($defined)->toHaveCount(35)
            ->toContain('additionalProperties', 'items', 'not', 'properties', 'nullable');

        $answered = [
            ...$defined,
            ...OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS,
            ...OpenApi30DownlevelEmitter::SILENT_SCHEMA_KEYWORDS,
            ...OpenApi30DownlevelEmitter::HANDLED_SCHEMA_KEYWORDS,
        ];

        $unanswered = array_values(array_filter(
            schemaKeywordVocabulary(),
            static fn (string $keyword): bool => ! str_starts_with($keyword, 'x-') && ! in_array($keyword, $answered, true),
        ));

        expect($unanswered)->toBe([], 'schema keywords 3.0 does not define and this emitter does not answer for');

        // And the other direction: nothing is dropped that 3.0 would have carried.
        expect(array_values(array_intersect(OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS, $defined)))->toBe([])
            ->and(array_values(array_intersect(OpenApi30DownlevelEmitter::SILENT_SCHEMA_KEYWORDS, $defined)))->toBe([]);
    });

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

/**
 * Everything an OpenAPI 3.0 reader rejects in an emitted document, read POSITIONALLY — the way the
 * emitter reads it. A banned schema keyword is only a keyword where a schema object stands, so a
 * component or a property merely SPELLED like one (`components.schemas.const`, a body property named
 * `if`) is the name it looks like rather than a keyword; and `jsonSchemaDialect` and `webhooks` are
 * document members, so they only mean anything at the root.
 *
 * The walk mirrors the emitter's: a `schema` member anywhere plus every entry of `components.schemas`,
 * and from each of those the seven positions a schema nests another at. `x-` members and the members
 * whose value is user data are not descended into, or an example keyed like a schema position would be
 * read as one — the mistake this scan exists to catch.
 *
 * @param  array<string, mixed>  $document
 * @return array{rejections: list<string>, positions: int}
 */
function downlevel30Scan(array $document): array
{
    $banned = [
        ...OpenApi30DownlevelEmitter::UNSUPPORTED_SCHEMA_KEYWORDS,
        ...OpenApi30DownlevelEmitter::SILENT_SCHEMA_KEYWORDS,
        'const',
    ];

    $rejections = [];
    $positions = 0;
    $escape = static fn (string $token): string => str_replace(['~', '/'], ['~0', '~1'], $token);

    $schema = function (array $node, string $pointer) use (&$schema, $banned, $escape, &$rejections, &$positions): void {
        $positions++;

        foreach ($banned as $keyword) {
            if (array_key_exists($keyword, $node)) {
                $rejections[] = $pointer.'/'.$keyword;
            }
        }

        // A 2020-12 `type` array is the other thing a 3.0 validator rejects outright.
        if (is_array($node['type'] ?? null)) {
            $rejections[] = $pointer.'/type is an array';
        }

        foreach (['items', 'not', 'additionalProperties'] as $keyword) {
            $subschema = $node[$keyword] ?? null;

            if (is_array($subschema)) {
                $schema($subschema, $pointer.'/'.$keyword);
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            $branches = $node[$keyword] ?? null;

            if (! is_array($branches)) {
                continue;
            }

            foreach (array_values($branches) as $index => $branch) {
                if (is_array($branch)) {
                    $schema($branch, $pointer.'/'.$keyword.'/'.$index);
                }
            }
        }

        $properties = $node['properties'] ?? null;

        if (is_array($properties)) {
            foreach ($properties as $name => $property) {
                if (is_array($property)) {
                    $schema($property, $pointer.'/properties/'.$escape((string) $name));
                }
            }
        }
    };

    $components = function (array $map, string $pointer) use ($schema, $escape): void {
        foreach ($map as $name => $member) {
            if (is_array($member)) {
                $schema($member, $pointer.'/'.$escape((string) $name));
            }
        }
    };

    $walk = function (mixed $node, string $pointer) use (&$walk, $schema, $components, $escape): void {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, 'x-') || in_array($key, ['const', 'default', 'enum', 'example', 'examples'], true)) {
                continue;
            }

            $child = $pointer.'/'.$escape($key);

            if ($key === 'schema' && is_array($value)) {
                $schema($value, $child);
            } elseif ($key === 'schemas' && $pointer === '#/components' && is_array($value)) {
                $components($value, $child);
            } else {
                $walk($value, $child);
            }
        }
    };

    foreach (['jsonSchemaDialect', 'webhooks'] as $member) {
        if (array_key_exists($member, $document)) {
            $rejections[] = '#/'.$member;
        }
    }

    $walk($document, '#');

    sort($rejections);

    return ['rejections' => $rejections, 'positions' => $positions];
}

it('emits nothing OpenAPI 3.0 cannot read', function (string $golden, int $positions): void {
    $scan = downlevel30Scan(json_decode(loadGolden($golden), true, flags: JSON_THROW_ON_ERROR));

    // The count is the anti-vacuity half: a walk that stopped reaching schemas would report no
    // rejections forever, and pass.
    expect($scan['rejections'])->toBe([])
        ->and($scan['positions'])->toBeGreaterThanOrEqual($positions);
})->with([
    'worked-example' => ['worked-example.openapi30.json', 8],
    'kitchen-sink' => ['kitchen-sink.openapi30.json', 8],
    'downlevel' => ['downlevel.openapi30.json', 24],
]);

describe('the scan that guards the 3.0 emission', function (): void {
    it('reads a keyword by the position it stands at, not by its spelling', function (): void {
        // Nothing here is a keyword: one is a component name, one is a property name, and 3.0 reads
        // both as the names they are.
        $emitted = (new OpenApi30DownlevelEmitter)->emit(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/things' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['if' => ['type' => 'string'], 'unevaluatedProperties' => ['type' => 'string']],
                ]]],
            ]]]]],
            'components' => ['schemas' => ['const' => ['type' => 'string'], 'contains' => ['type' => 'string']]],
        ]));

        $scan = downlevel30Scan(json_decode($emitted, true, flags: JSON_THROW_ON_ERROR));

        expect($scan['rejections'])->toBe([])
            ->and($scan['positions'])->toBe(5);
    });

    it('still finds a banned construct standing where 3.0 reads it as one', function (array $document, array $rejections): void {
        expect(downlevel30Scan($document)['rejections'])->toBe($rejections);
    })->with([
        'a component schema' => [
            ['components' => ['schemas' => ['S' => ['type' => 'object', 'if' => ['type' => 'string']]]]],
            ['#/components/schemas/S/if'],
        ],
        'a property schema' => [
            ['components' => ['schemas' => ['S' => ['properties' => ['a' => ['$comment' => 'aside']]]]]],
            ['#/components/schemas/S/properties/a/$comment'],
        ],
        'an allOf branch' => [
            ['components' => ['schemas' => ['S' => ['allOf' => [['type' => 'string'], ['const' => 1]]]]]],
            ['#/components/schemas/S/allOf/1/const'],
        ],
        'an items schema' => [
            ['components' => ['schemas' => ['S' => ['items' => ['prefixItems' => []]]]]],
            ['#/components/schemas/S/items/prefixItems'],
        ],
        'an inline body schema' => [
            ['paths' => ['/things' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
                'schema' => ['type' => ['string', 'null']],
            ]]]]]]]],
            ['#/paths/~1things/get/responses/200/content/application~1json/schema/type is an array'],
        ],
        'the document members 3.0 lacks' => [
            ['jsonSchemaDialect' => 'https://json-schema.org/draft/2020-12/schema', 'webhooks' => []],
            ['#/jsonSchemaDialect', '#/webhooks'],
        ],
    ]);
});

it('keeps a projected mock hint through the 3.0 downlevel', function (): void {
    // The projection happens in the 3.2 pass this emitter chains off, so what arrives here is an
    // ordinary `x-` member — and 3.0 passes those through untouched, with nothing to report.
    $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => [
            'type' => 'object',
            'properties' => ['email' => ['type' => 'string', 'x-docuccino' => ['mock' => ['faker' => 'safeEmail']]]],
        ]]],
    ]), (new EmitOptions)->withMockFakerKey('x-faker'));

    $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['components']['schemas']['S']['properties']['email'])->toBe(['type' => 'string', 'x-faker' => 'safeEmail'])
        ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe([]);
});
