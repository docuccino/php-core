<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi30Upgrade;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\UnreadableDocumentException;
use Opis\JsonSchema\Validator;

/**
 * A document read at OpenAPI 3.0 and exported at 3.1 or 3.2 says what the 3.0 source said. The two
 * versions spell three things in one Schema Object differently, and a 3.0 spelling under a newer label
 * is a different statement there: `nullable` is no keyword at all from 3.1, so the null it admitted is
 * refused; a boolean `exclusiveMinimum` is invalid where 2020-12 wants the bound itself; and the
 * members beside a `$ref`, which 3.0 ignores, start to apply.
 *
 * Each answer below is asked of a validator rather than of the emitter: the source as 3.0.4 reads it
 * (`openApi30Admits()`), and the export as JSON Schema 2020-12.
 */

/**
 * A 3.0 document whose `S` is $schema, beside the `Other` a `$ref` row names.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function upgradeSource(array $schema): array
{
    return [
        'openapi' => '3.0.3',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => [
            'S' => $schema,
            'Other' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ]],
    ];
}

/** Whether `#/components/schemas/S` in an emitted 3.1 or 3.2 document admits $value, read as JSON Schema 2020-12. */
function upgradeAdmits(string $emitted, mixed $value): bool
{
    $document = json_decode($emitted, flags: JSON_THROW_ON_ERROR);

    return (new Validator)->validate(
        json_decode((string) json_encode($value)),
        (object) ['$ref' => '#/components/schemas/S', 'components' => $document->components],
    )->isValid();
}

/** @return list<mixed> */
function upgradeProbeValues(): array
{
    return [null, 'a', 'z', '', 0, 1, -1, 5, 6, 1.5, ['a' => 'x'], ['b' => 1], ['a' => 'x', 'b' => 1], [null], ['a'], [1], ['n' => null], ['n' => 1], ['n' => 'x']];
}

it('admits at 3.1 and 3.2 exactly what the 3.0 source admits', function (string $format, array $schema): void {
    $source = upgradeSource($schema);
    $sourceJson = (string) json_encode($source, JSON_THROW_ON_ERROR);
    $result = Formats::emit($format, UirDocument::fromArray($source), new EmitOptions);

    foreach (upgradeProbeValues() as $value) {
        expect(upgradeAdmits($result->output, $value))
            ->toBe(openApi30Admits($sourceJson, '/components/schemas/S', $value), json_encode($value).' in '.$result->output);
    }

    // A validator reads a boolean exclusive bound draft-4's way whatever the dialect, so it cannot see this
    // half: 2020-12 says the value of `exclusiveMinimum` and `exclusiveMaximum` "MUST be a number".
    expect($result->output)->not->toContain('"nullable"')
        ->and($result->output)->not->toMatch('/"exclusiveM(?:in|ax)imum": (?:true|false)/')
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $result->report->diagnostics))->not->toContain('document.openapi-invalid');
})->with(['openapi-3.1', 'openapi-3.2'])->with([
    'a nullable type' => [['type' => 'string', 'nullable' => true]],
    // 3.0.4: "Other Schema Object constraints retain their defined behavior, and therefore may disallow
    // the use of null" — a value list without null still refuses it.
    'a nullable type beside a value list without null' => [['type' => 'string', 'nullable' => true, 'enum' => ['a']]],
    'a nullable type beside a value list naming null' => [['type' => 'string', 'nullable' => true, 'enum' => ['a', null]]],
    'a type nullable says false to' => [['type' => 'string', 'nullable' => false]],
    'an exclusive minimum' => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true]],
    'an exclusive maximum' => [['type' => 'integer', 'maximum' => 5, 'exclusiveMaximum' => true]],
    'an inclusive bound said so' => [['type' => 'integer', 'maximum' => 5, 'exclusiveMaximum' => false]],
    'an exclusive flag qualifying no bound' => [['type' => 'integer', 'exclusiveMinimum' => true]],
    'a nullable exclusive minimum' => [['type' => 'integer', 'nullable' => true, 'minimum' => 0, 'exclusiveMinimum' => true]],
    'a $ref whose siblings 3.0 ignores' => [['$ref' => '#/components/schemas/Other', 'required' => ['b'], 'description' => 'Ignored.']],
    'a nullable property' => [['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'nullable' => true]]]],
    'nullable items' => [['type' => 'array', 'items' => ['type' => 'string', 'nullable' => true]]],
    'an exclusive bound inside a composition' => [['allOf' => [['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => true]]]],
    'a nullable schema under not' => [['not' => ['type' => 'string', 'nullable' => true]]],
    'a nullable additional property' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'nullable' => true]]],
    'a $ref with siblings inside a property' => [['type' => 'object', 'properties' => ['n' => ['$ref' => '#/components/schemas/Other', 'nullable' => true]]]],
]);

it('restates the 3.0 schema in the 2020-12 spelling of the same constraint', function (): void {
    $result = Formats::emit('openapi-3.2', UirDocument::fromArray(upgradeSource(
        ['type' => 'integer', 'nullable' => true, 'minimum' => 0, 'exclusiveMinimum' => true],
    )), new EmitOptions);

    expect(json_decode($result->output, true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['S'])
        ->toBe(['type' => ['integer', 'null'], 'exclusiveMinimum' => 0]);
});

/*
 * Where 3.0's own text and the generators a 3.0 document is mostly written for part: `nullable` with no
 * `type` beside it. 3.0.4 says it "only takes effect if `type` is explicitly defined", and every generator
 * reads `allOf: [$ref]` beside it as "nullable X" — it is how 3.0 spells a nullable reference at all, and
 * how the 3.0 downlevel writes one. The wider reading is the true one to publish: it agrees with the
 * source on every value but null, admits null, and says so.
 */
it('reads a nullable with no type beside it as admitting null, and says so', function (array $schema, array $published): void {
    $source = upgradeSource($schema);
    $sourceJson = (string) json_encode($source, JSON_THROW_ON_ERROR);
    $result = Formats::emit('openapi-3.1', UirDocument::fromArray($source), new EmitOptions);

    expect(json_decode($result->output, true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['S'])->toBe($published)
        ->and(upgradeAdmits($result->output, null))->toBeTrue()
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $result->report->diagnostics))->toContain('upgrade.nullable-without-type');

    foreach (array_slice(upgradeProbeValues(), 1) as $value) {
        expect(upgradeAdmits($result->output, $value))
            ->toBe(openApi30Admits($sourceJson, '/components/schemas/S', $value), json_encode($value).' in '.$result->output);
    }
})->with([
    'a nullable reference' => [
        ['allOf' => [['$ref' => '#/components/schemas/Other']], 'nullable' => true, 'description' => 'The other one.'],
        ['description' => 'The other one.', 'anyOf' => [['$ref' => '#/components/schemas/Other'], ['type' => 'null']]],
    ],
    'a nullable tagged union' => [
        ['oneOf' => [['$ref' => '#/components/schemas/Other']], 'discriminator' => ['propertyName' => 'a'], 'nullable' => true],
        ['anyOf' => [['oneOf' => [['$ref' => '#/components/schemas/Other']], 'discriminator' => ['propertyName' => 'a']], ['type' => 'null']]],
    ],
    'a nullable value list' => [
        ['enum' => ['a', 'z'], 'nullable' => true],
        ['anyOf' => [['enum' => ['a', 'z']], ['type' => 'null']]],
    ],
]);

it('leaves a nullable that asserts nothing beside it as nothing, since null already passes', function (): void {
    $result = Formats::emit('openapi-3.1', UirDocument::fromArray(upgradeSource(['nullable' => true, 'description' => 'Anything.'])), new EmitOptions);

    expect(json_decode($result->output, true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['S'])->toBe(['description' => 'Anything.'])
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $result->report->diagnostics))->not->toContain('upgrade.nullable-without-type');
});

/*
 * Admitting null beside what a schema asserts nests it two levels deeper, and a 3.0 document the reader
 * takes can nest enough of those to pass the bound every writer has. Past it, the export and every diff
 * against the document threw. The deepest ones are read as admitting any value instead, which is wider
 * than either reading of the source and so still true, and said.
 */
it('never restates a 3.0 document deeper than it can be written out', function (string $keyword, int $levels): void {
    $schema = ['type' => 'string'];
    $value = 'x';

    for ($level = 0; $level < $levels; $level++) {
        $schema = ['nullable' => true, 'description' => 'Level '.$level, $keyword => $keyword === 'items' ? $schema : ['a' => $schema]];
        $value = $keyword === 'items' ? [$value] : ['a' => $value];
    }

    // As deep as the reader takes: one level more is refused.
    $document = (new DocumentReader)->read((string) json_encode(upgradeSource($schema)))->uir();

    foreach (Formats::ids() as $format) {
        expect(static fn () => Formats::emit($format, $document, new EmitOptions))->not->toThrow(Throwable::class);
    }

    $result = Formats::emit('openapi-3.1', $document, new EmitOptions);
    $own = (new DocumentReader)->read($result->output)->uir();
    $newer = UirDocument::fromArray(['openapi' => '3.1.0'] + upgradeSource(['type' => 'object']));

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $result->report->diagnostics))->toContain('upgrade.nullable-too-deep')
        ->and(upgradeAdmits($result->output, null))->toBeTrue()
        ->and(upgradeAdmits($result->output, $value))->toBeTrue()
        ->and((new DocumentDiffer)->diff($document, $own)->changes)->toBe([])
        ->and((new DocumentDiffer)->diff($document, $newer)->changes)->not->toBe([])
        ->and((new DocumentDiffer)->diff($newer, $document)->changes)->not->toBe([]);

    expect(static fn () => (new DocumentReader)->read((string) json_encode(upgradeSource([$keyword => $keyword === 'items' ? $schema : ['a' => $schema]]))))
        ->toThrow(UnreadableDocumentException::class);
})->with([
    'properties' => ['properties', 62],
    'items' => ['items', 124],
]);

/*
 * The 3.0 export of a 3.0 document reads the restatement back, so it lands where the source started —
 * and so does a 3.0 export of the 3.1 or 3.2 artifact, read back as a document of its own. Every row is a
 * spelling the 3.0 downlevel writes, which is the round trip a re-served artifact takes.
 */
it('round-trips a 3.0 schema through every newer export and back', function (array $schema): void {
    $source = UirDocument::fromArray(upgradeSource($schema));
    $as30 = static fn (string $json): mixed => json_decode($json, true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['S'];

    $direct = Formats::emit('openapi-3.0', $source, new EmitOptions)->output;
    expect($as30($direct))->toEqual($schema);

    foreach (['openapi-3.1', 'openapi-3.2'] as $format) {
        $newer = UirDocument::fromArray(json_decode(Formats::emit($format, $source, new EmitOptions)->output, true, flags: JSON_THROW_ON_ERROR));

        expect(Formats::emit('openapi-3.0', $newer, new EmitOptions)->output)->toBe($direct, $format);
    }

    $as32 = UirDocument::fromArray(json_decode(Formats::emit('openapi-3.2', $source, new EmitOptions)->output, true, flags: JSON_THROW_ON_ERROR));
    expect(Formats::emit('openapi-3.1', $as32, new EmitOptions)->output)->toBe(Formats::emit('openapi-3.1', $source, new EmitOptions)->output);
})->with([
    'a nullable type' => [['type' => 'string', 'nullable' => true]],
    'a nullable type beside a value list without null' => [['type' => 'string', 'nullable' => true, 'enum' => ['a']]],
    'an exclusive minimum' => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true]],
    'a nullable exclusive maximum' => [['type' => 'number', 'nullable' => true, 'maximum' => 1.5, 'exclusiveMaximum' => true]],
    'a nullable reference' => [['allOf' => [['$ref' => '#/components/schemas/Other']], 'nullable' => true]],
    'a described nullable reference' => [['allOf' => [['$ref' => '#/components/schemas/Other']], 'nullable' => true, 'description' => 'The other one.']],
    'a nullable tagged union' => [['oneOf' => [['$ref' => '#/components/schemas/Other']], 'discriminator' => ['propertyName' => 'a'], 'nullable' => true]],
    'nested' => [['type' => 'object', 'properties' => ['n' => ['type' => 'array', 'items' => ['type' => 'integer', 'nullable' => true, 'minimum' => 0, 'exclusiveMinimum' => true]]]]],
]);

/*
 * A diagnostic earns its place where the artifact differs from what the source said. Exported at 3.0, a
 * 3.0 construct the restatement brings back as written changed nothing a reader can see, so neither the
 * restatement nor the downlevel reading it back reports anything — including the no-type `nullable`,
 * whose 3.0 spelling already says both of the readings a newer version has to choose between.
 */
it('raises nothing exporting a 3.0 construct at 3.0 that comes back as written', function (array $schema): void {
    $result = Formats::emit('openapi-3.0', UirDocument::fromArray(upgradeSource($schema)), new EmitOptions);

    expect(json_decode($result->output, true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['S'])->toEqual($schema)
        ->and(array_map(static fn (Diagnostic $d): string => $d->code.': '.$d->message, $result->report->diagnostics))->toBe([]);
})->with([
    'a nullable type' => [['type' => 'string', 'nullable' => true]],
    'a nullable type beside a value list without null' => [['type' => 'string', 'nullable' => true, 'enum' => ['a']]],
    'an exclusive minimum' => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true]],
    'a nullable reference' => [['allOf' => [['$ref' => '#/components/schemas/Other']], 'nullable' => true, 'description' => 'The other one.']],
    'a nullable tagged union' => [['oneOf' => [['$ref' => '#/components/schemas/Other']], 'discriminator' => ['propertyName' => 'a'], 'nullable' => true]],
    'a nullable value list with no type' => [['enum' => ['a', 'z'], 'nullable' => true]],
    'a nullable composition with no type' => [['allOf' => [['$ref' => '#/components/schemas/Other'], ['required' => ['b']]], 'nullable' => true]],
    'a nullable with nothing beside it' => [['nullable' => true, 'description' => 'Anything.']],
    'nested' => [['type' => 'object', 'properties' => ['n' => ['type' => 'array', 'items' => ['allOf' => [['$ref' => '#/components/schemas/Other']], 'nullable' => true]]]]],
]);

it('still reports a $ref sibling it drops from a 3.0 export, since that one changes', function (): void {
    $result = Formats::emit('openapi-3.0', UirDocument::fromArray(upgradeSource(['$ref' => '#/components/schemas/Other', 'description' => 'Ignored.'])), new EmitOptions);

    expect(json_decode($result->output, true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['S'])->toBe(['$ref' => '#/components/schemas/Other'])
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $result->report->diagnostics))->toBe(['upgrade.ref-siblings']);
});

/*
 * A collection is built from the 3.2 array, so what it reports about reading a 3.0 document is exactly
 * what the 3.2 export reports: nothing more, and nothing less.
 */
it('reports from a Postman collection what the 3.2 export it is built from reports, and no more', function (): void {
    $document = UirDocument::fromArray(loadFixture('openapi30-source.json'));
    $reading = static fn (string $format): array => array_values(array_map(
        static fn (Diagnostic $d): string => $d->code.': '.$d->message,
        array_filter(Formats::emit($format, $document, new EmitOptions)->report->diagnostics, static fn (Diagnostic $d): bool => str_starts_with($d->code, 'upgrade.')),
    ));

    expect($reading('postman'))->toBe($reading('openapi-3.2'))
        ->and($reading('openapi-3.2'))->toHaveCount(4);
});

/*
 * A document stating 3.1 or 3.2 is already in the meaning it is exported in. `nullable` there is an
 * unknown keyword, which is the author's to have written and no one's to reinterpret.
 */
it('leaves a document stating a newer version as it reads', function (string $version): void {
    $schema = ['type' => 'integer', 'nullable' => true, 'exclusiveMinimum' => 0];
    $source = upgradeSource($schema);
    $source['openapi'] = $version;
    $result = Formats::emit('openapi-3.2', UirDocument::fromArray($source), new EmitOptions);

    expect(json_decode($result->output, true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['S'])->toEqual($schema);
})->with(['3.1.0', '3.1.1', '3.2.0']);

it('reads a version as 3.0 by its major and minor alone', function (string $version, bool $reads30): void {
    expect(OpenApi30Upgrade::reads30($version))->toBe($reads30);
})->with([
    ['3.0', true],
    ['3.0.0', true],
    ['3.0.3', true],
    ['3.0.4', true],
    ['3.1.0', false],
    ['3.2.0', false],
    ['3.00', false],
    ['30.0', false],
    ['2.0', false],
]);

/*
 * Every position a Schema Object or a Reference Object stands at in a 3.0 document, each holding a
 * probe: a nullable string where a schema goes, and a described `$ref` where a reference does. The first
 * test holds the list of positions against the vendored 3.0 meta-schema, so the probe document cannot
 * fall short of what 3.0 defines; the second counts the probes in the export, so a walk that stops
 * reaching one fails rather than reporting nothing to rewrite.
 */
describe('every position a 3.0 document holds a schema or a reference at', function (): void {
    /** @return array<string, mixed> */
    $document = static function (): array {
        $schema = ['type' => 'string', 'nullable' => true];
        $ref = static fn (string $to): array => ['$ref' => '#/components/'.$to, 'description' => 'Not this one.'];
        $ok = ['200' => ['description' => 'OK']];

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/p/{id}' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $schema], $ref('parameters/Q')],
                'post' => [
                    'parameters' => [
                        ['name' => 'h', 'in' => 'header', 'schema' => $schema, 'examples' => ['e' => $ref('examples/E')]],
                        ['name' => 'c', 'in' => 'query', 'content' => ['application/json' => ['schema' => $schema]]],
                        $ref('parameters/Q'),
                    ],
                    'requestBody' => ['content' => ['multipart/form-data' => [
                        'schema' => $schema,
                        'examples' => ['e' => $ref('examples/E')],
                        'encoding' => ['f' => ['headers' => ['X-E' => ['schema' => $schema], 'X-R' => $ref('headers/H')]]],
                    ]]],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'headers' => [
                                // A header map holds names, so this is a header rather than an extension.
                                'x-rate' => ['schema' => $schema, 'examples' => ['e' => $ref('examples/E')]],
                                'X-C' => ['content' => ['text/plain' => ['schema' => $schema]]],
                                'X-R' => $ref('headers/H'),
                            ],
                            'content' => ['application/json' => ['schema' => $schema]],
                            'links' => ['l' => $ref('links/L')],
                        ],
                        '404' => $ref('responses/R'),
                        'default' => $ref('responses/R'),
                    ],
                    'callbacks' => [
                        'cb' => ['{$request.body#/url}' => ['post' => [
                            'requestBody' => ['content' => ['application/json' => ['schema' => $schema]]],
                            'responses' => $ok,
                        ]]],
                        'cb2' => $ref('callbacks/C'),
                    ],
                ],
                'put' => ['requestBody' => $ref('requestBodies/B'), 'responses' => $ok],
            ]],
            'components' => [
                'schemas' => ['S' => $schema, 'P' => ['type' => 'object'], 'T' => $ref('schemas/P')],
                'responses' => ['R' => ['description' => 'R', 'content' => ['application/json' => ['schema' => $schema]]], 'R2' => $ref('responses/R')],
                'parameters' => ['Q' => ['name' => 'q', 'in' => 'query', 'schema' => $schema], 'Q2' => $ref('parameters/Q')],
                'examples' => ['E' => ['value' => 1], 'E2' => $ref('examples/E')],
                'requestBodies' => ['B' => ['content' => ['application/json' => ['schema' => $schema]]], 'B2' => $ref('requestBodies/B')],
                'headers' => ['H' => ['schema' => $schema], 'H2' => $ref('headers/H')],
                'securitySchemes' => ['K' => ['type' => 'apiKey', 'name' => 'k', 'in' => 'header'], 'K2' => $ref('securitySchemes/K')],
                'links' => ['L' => ['operationId' => 'x'], 'L2' => $ref('links/L')],
                'callbacks' => [
                    'C' => ['{$request.body#/url}' => ['post' => ['responses' => ['200' => [
                        'description' => 'OK',
                        'content' => ['application/json' => ['schema' => $schema]],
                    ]]]]],
                    'C2' => $ref('callbacks/C'),
                ],
            ],
        ];
    };

    it('probes every position the 3.0 meta-schema defines', function (): void {
        $meta = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/openapi/openapi-v3.0.schema.json'), true, flags: JSON_THROW_ON_ERROR);
        $reaches = static function (mixed $node) use (&$reaches): bool {
            if (! is_array($node)) {
                return false;
            }
            $ref = $node['$ref'] ?? null;
            if (is_string($ref) && preg_match('~/(Schema|Reference)$~', $ref) === 1) {
                return true;
            }
            foreach ($node as $child) {
                if ($reaches($child)) {
                    return true;
                }
            }

            return false;
        };

        $defined = [];
        foreach ($meta['definitions'] as $name => $definition) {
            // A Schema Object's own subschemas are the schema walk's, read off SchemaKeywords.
            if ($name === 'Schema') {
                continue;
            }
            foreach ([...($definition['properties'] ?? []), ...($definition['patternProperties'] ?? [])] as $member => $body) {
                if ($reaches($body)) {
                    $defined[] = $name.'.'.$member;
                }
            }
        }
        sort($defined);

        $probed = [
            'Components.callbacks', 'Components.examples', 'Components.headers', 'Components.links',
            'Components.parameters', 'Components.requestBodies', 'Components.responses', 'Components.schemas',
            'Components.securitySchemes', 'Encoding.headers', 'Header.examples', 'Header.schema',
            'MediaType.examples', 'MediaType.schema', 'Operation.callbacks', 'Operation.parameters',
            'Operation.requestBody', 'Parameter.examples', 'Parameter.schema', 'PathItem.parameters',
            'Response.headers', 'Response.links', 'Responses.^[1-5](?:\d{2}|XX)$', 'Responses.default',
        ];
        sort($probed);

        expect($defined)->toBe($probed);
    });

    it('restates the probe at every one of them', function (string $format) use ($document): void {
        $result = Formats::emit($format, UirDocument::fromArray($document()), new EmitOptions);
        $emitted = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        $counts = ['nullable' => 0, 'restated' => 0, 'references' => 0, 'described' => 0];
        $scan = static function (mixed $node) use (&$scan, &$counts): void {
            if (! is_array($node)) {
                return;
            }
            $counts['nullable'] += (int) array_key_exists('nullable', $node);
            $counts['restated'] += (int) ($node === ['type' => ['string', 'null']]);
            if (is_string($node['$ref'] ?? null)) {
                $counts['references']++;
                $counts['described'] += (int) (count($node) > 1);
            }
            foreach ($node as $child) {
                $scan($child);
            }
        };
        $scan($emitted);

        expect($counts)->toBe(['nullable' => 0, 'restated' => 15, 'references' => 21, 'described' => 0])
            ->and(array_map(static fn (Diagnostic $d): string => $d->code, $result->report->diagnostics))->each->toBe('upgrade.ref-siblings');
    })->with(['openapi-3.1', 'openapi-3.2']);
});

/*
 * A hand-written 3.0 document in the shapes 3.0 authors write — nullable fields, a nullable reference and
 * tagged union, boolean bounds, a described `$ref` — pinned at every OpenAPI export. The generator's own
 * corpus is 3.2 throughout, so without this nothing byte-locked stands in the population a 3.0 reading
 * acts on. The 3.0 golden is the source as 3.0 spells it, bar the two `$ref` siblings 3.0 itself ignores
 * and an `exclusiveMaximum: false` that says nothing.
 */
it('emits a 3.0 document byte-identical to the committed golden at every OpenAPI version', function (string $format, string $golden): void {
    $result = Formats::emit($format, UirDocument::fromArray(loadFixture('openapi30-source.json')), new EmitOptions);

    assertGoldenAt(dirname(__DIR__).'/Fixtures/golden/'.$golden, $result->output);
})->with([
    ['openapi-3.2', 'openapi30-source.openapi32.json'],
    ['openapi-3.1', 'openapi30-source.openapi31.json'],
    ['openapi-3.0', 'openapi30-source.openapi30.json'],
]);
