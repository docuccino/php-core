<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\SpecValidation\EmittedSpecCheck;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;

/**
 * The emitters holding their own output to the published OpenAPI schema, proved by REFUSAL rather than
 * by silence.
 *
 * `OpenApiMetaSchemaTest` says the corpus is clean, which is what a working check looks like and also
 * what a check that never runs looks like. So every assertion here hands an emitter a document whose
 * artifact is broken in one named way and reads the diagnostic back off the emit report — the seam
 * `docuccino:export` and the viewer actually go through. Three formats each, because the check runs per
 * format against a different meta-schema, and the 3.0 downlevel reaches its own.
 */

/** The three OpenAPI formats, as a dataset — read off the emitter table, never listed here. */
function specCheckFormats(): array
{
    $formats = [];

    foreach (Formats::ids() as $id) {
        if (str_starts_with($id, 'openapi-')) {
            $formats[$id] = [$id];
        }
    }

    expect($formats)->toHaveCount(3);

    return $formats;
}

/**
 * A document that emits cleanly at every version, so each case below measures its own mutation.
 *
 * @param  array<string, mixed>  $overrides
 */
function specCheckDocument(array $overrides = []): UirDocument
{
    return UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => [
            'operationId' => 'things.index',
            'responses' => ['200' => ['description' => 'OK']],
        ]]],
        ...$overrides,
    ]);
}

/**
 * Every `document.openapi-invalid` message one emission raises — the defects that are OURS, which is
 * why the severity and the "report it" help are asserted here rather than at each call.
 *
 * @return list<string>
 */
function specCheckFindings(string $format, UirDocument $document, EmitOptions $options = new EmitOptions): array
{
    $report = Formats::emit($format, $document, $options)->report;

    $messages = [];
    foreach ($report->diagnostics as $diagnostic) {
        if ($diagnostic->code === 'document.openapi-invalid') {
            expect($diagnostic->severity)->toBe(Severity::Error)
                ->and($diagnostic->help)->toContain('github.com/docuccino/docuccino/issues');

            $messages[] = $diagnostic->message;
        }
    }

    return $messages;
}

/**
 * The other half: every `document.duplicate-operation-id` message, with the properties that make it
 * addressed to the author rather than to us — a warning, and help that names what to change instead of
 * where to file a bug.
 *
 * @return list<string>
 */
function specCheckDuplicates(string $format, UirDocument $document): array
{
    $messages = [];

    foreach (Formats::emit($format, $document, new EmitOptions)->report->diagnostics as $diagnostic) {
        if ($diagnostic->code === 'document.duplicate-operation-id') {
            expect($diagnostic->severity)->toBe(Severity::Warning)
                ->and($diagnostic->help)->toContain('#[OperationId]')
                ->and($diagnostic->help)->toContain('representation.operation_id')
                ->and($diagnostic->help)->not->toContain('github.com/docuccino/docuccino/issues')
                ->and($diagnostic->message)->not->toContain('defect');

            $messages[] = $diagnostic->message;
        }
    }

    return $messages;
}

it('says nothing about a document that answers to its own schema', function (string $format): void {
    expect(specCheckFindings($format, specCheckDocument()))->toBe([]);
})->with(specCheckFormats());

/**
 * The shape that shipped: an empty `paths` MAP written as a sequence. Asserted on the array rather than
 * through an emitter, because the emitters get it right — what is proved here is that the check would
 * see it if one stopped.
 */
it('refuses an empty map written as a sequence, at every version', function (string $format, string $version): void {
    $sound = sprintf('{"openapi":"%s","info":{"title":"T","version":"1.0.0"},"paths":%%s}', $version);

    expect(EmittedSpecCheck::diagnostics($format, sprintf($sound, '{}')))->toBe([])
        ->and(EmittedSpecCheck::diagnostics($format, sprintf($sound, '[]')))->toHaveCount(1)
        ->and(EmittedSpecCheck::diagnostics($format, sprintf($sound, '[]'))[0]->message)->toContain('/paths');
})->with([
    'openapi-3.2' => ['openapi-3.2', '3.2.0'],
    'openapi-3.1' => ['openapi-3.1', '3.1.0'],
    'openapi-3.0' => ['openapi-3.0', '3.0.4'],
]);

/**
 * A schema shape no version accepts, reached through the emitters. `type` is a string or an array of
 * strings everywhere; a number is none of them, and the 3.1/3.2 meta-schemas leave Schema Objects
 * unconstrained — so this is one the SHAPE check can only see at 3.0, and it is recorded that way
 * rather than asserted uniformly. A row that passes is the measurement.
 */
it('refuses a schema shape the version does not accept, where that version can see it', function (string $format, bool $refuses): void {
    $document = specCheckDocument(['paths' => ['/things' => ['get' => [
        'operationId' => 'things.index',
        'responses' => ['200' => [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => ['type' => 12]]],
        ]],
    ]]]]);

    expect(specCheckFindings($format, $document) === [])->toBe(! $refuses);
})->with([
    // 3.1 and 3.2 declare `$defs/schema` as `type: [object, boolean]` and nothing more, so a corrupted
    // member INSIDE a Schema Object is invisible to them. 3.0 enumerates its Schema Object and sees it.
    'openapi-3.2' => ['openapi-3.2', false],
    'openapi-3.1' => ['openapi-3.1', false],
    'openapi-3.0' => ['openapi-3.0', true],
]);

/**
 * A key gate — a `paths` member not starting with `/`. The 3.1 and 3.2 meta-schemas enforce it only
 * through `unevaluatedProperties`, which opis mis-evaluates and the oracle therefore recovers by hand;
 * this is that recovery reaching a real emission at every version.
 */
it('refuses a paths key that is not a path, at every version', function (string $format): void {
    $document = specCheckDocument(['paths' => ['things' => ['get' => [
        'operationId' => 'things.index',
        'responses' => ['200' => ['description' => 'OK']],
    ]]]]);

    expect(specCheckFindings($format, $document))->not->toBe([]);
})->with(specCheckFormats());

/**
 * `operationId` uniqueness: a spec rule JSON Schema cannot express at all, so every meta-schema accepts
 * the document while a generated client silently loses one of the two methods to the collision.
 *
 * Reported under its own code, and that is the whole point of the code. Every other finding in this
 * file needs an emitter defect to happen; this one needs two routes onto one controller action, which
 * `representation.operation_id: controller-method` — shipped, documented — mints one id for. So it is
 * the author's to fix, it is a warning, and it must not be able to fail an export.
 */
it('warns the author about two operations that share an operationId, at every version', function (string $format): void {
    $operation = static fn (): array => ['operationId' => 'things.index', 'responses' => ['200' => ['description' => 'OK']]];

    $document = specCheckDocument(['paths' => [
        '/things' => ['get' => $operation()],
        '/others' => ['get' => $operation()],
    ]]);

    $duplicates = specCheckDuplicates($format, $document);

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0])->toContain('things.index')
        // And not as OUR defect: nothing on the error channel, so nothing that fails an export.
        ->and(specCheckFindings($format, $document))->toBe([]);
})->with(specCheckFormats());

/**
 * A dangling `$ref`: the other rule no meta-schema carries, and the one with the sharpest consequence —
 * the type a client would have generated is simply absent, and the document validates at every version.
 */
it('refuses a $ref that names nothing the document defines, at every version', function (string $format): void {
    $document = specCheckDocument(['paths' => ['/things' => ['get' => [
        'operationId' => 'things.index',
        'responses' => ['200' => [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Missing']]],
        ]],
    ]]]]);

    $findings = specCheckFindings($format, $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0])->toContain('#/components/schemas/Missing');
})->with(specCheckFormats());

/** And silence where the same reference resolves, so the check is not simply always positive. */
it('says nothing about a $ref that resolves, at every version', function (string $format): void {
    $document = specCheckDocument([
        'paths' => ['/things' => ['get' => [
            'operationId' => 'things.index',
            'responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']]],
            ]],
        ]]],
        'components' => ['schemas' => ['Thing' => ['type' => 'object']]],
    ]);

    expect(specCheckFindings($format, $document))->toBe([]);
})->with(specCheckFormats());

/**
 * The false-positive half, which matters more than the true-positive one: a finding here accuses the
 * emitter of a defect it does not have, and the reader cannot check it. Every position OpenAPI lets an
 * application fill with arbitrary data, each holding something shaped exactly like a broken reference.
 */
it('reads a $ref-shaped value in a data position as data', function (string $position, array $overrides): void {
    expect(specCheckFindings('openapi-3.2', specCheckDocument($overrides)))->toBe([], $position);
})->with(function (): array {
    $ref = ['$ref' => '#/nothing/at/all'];

    $mediaType = static fn (array $media): array => ['paths' => ['/things' => ['get' => [
        'operationId' => 'things.index',
        'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => $media]]],
    ]]]];

    return [
        'a media type example' => ['a media type example', $mediaType(['schema' => ['type' => 'object'], 'example' => $ref])],
        'an Example Object value' => ['an Example Object value', $mediaType(['schema' => ['type' => 'object'], 'examples' => ['one' => ['value' => $ref]]])],
        'a schema default' => ['a schema default', $mediaType(['schema' => ['type' => 'object', 'default' => $ref]])],
        'a schema const' => ['a schema const', $mediaType(['schema' => ['const' => $ref]])],
        'a schema enum member' => ['a schema enum member', $mediaType(['schema' => ['enum' => [$ref]]])],
        'a schema examples list' => ['a schema examples list', $mediaType(['schema' => ['type' => 'object', 'examples' => [$ref]]])],
        'a property named $ref' => ['a property named $ref', $mediaType(['schema' => ['type' => 'object', 'properties' => ['$ref' => ['type' => 'string']]]])],
        'a Link Object requestBody' => ['a Link Object requestBody', ['paths' => ['/things' => ['get' => [
            'operationId' => 'things.index',
            'responses' => ['200' => ['description' => 'OK', 'links' => ['self' => ['operationId' => 'things.index', 'requestBody' => $ref]]]],
        ]]]]],
        'a specification extension' => ['a specification extension', ['x-vendor' => $ref]],
    ];
});

/**
 * The emitters call this on every emission, JSON and YAML alike, and a document whose defect is in the
 * DOCUMENT is reported whichever serialisation is asked for. No longer true by construction: each
 * carrier is now read back on its own, so agreeing is a fact about the two writers rather than about
 * one of them being validated twice.
 */
it('reports the same findings for a YAML emission as for a JSON one', function (string $format): void {
    $document = specCheckDocument(['paths' => ['things' => ['get' => [
        'operationId' => 'things.index',
        'responses' => ['200' => ['description' => 'OK']],
    ]]]]);

    $codes = static fn (EmitOptions $options): array => array_map(
        static fn ($d): string => $d->code.' '.$d->message,
        Formats::emit($format, $document, $options)->report->diagnostics,
    );

    expect($codes((new EmitOptions)->withYaml()))->toBe($codes(new EmitOptions))
        ->and($codes(new EmitOptions))->not->toBe([]);
})->with(specCheckFormats());

/**
 * And the reason each carrier is read on its own: a YAML-writer defect lives in the YAML bytes and
 * nowhere else, so validating the JSON serialisation of a YAML emission leaves the writer that has
 * actually shipped one — `paths: []` for an empty `paths` MAP — answering to nothing.
 *
 * Stated at the bytes rather than through an emitter because the emitter gets it right. Same document
 * in both carriers, one of them written the broken way: JSON clean, YAML refused.
 */
it('reads the YAML bytes, so a defect only the YAML carrier has is caught', function (string $format, string $version): void {
    $json = sprintf('{"openapi":"%s","info":{"title":"T","version":"1.0.0"},"paths":{}}', $version);
    $yaml = sprintf("openapi: '%s'\ninfo:\n  title: T\n  version: '1.0.0'\npaths: []\n", $version);

    // The control: the same document written the RIGHT way in YAML says nothing, so the row below is
    // about the empty map and not about the parse.
    $sound = sprintf("openapi: '%s'\ninfo:\n  title: T\n  version: '1.0.0'\npaths: {  }\n", $version);

    expect(EmittedSpecCheck::diagnostics($format, $json))->toBe([])
        ->and(EmittedSpecCheck::diagnostics($format, $sound, yaml: true))->toBe([])
        ->and(EmittedSpecCheck::diagnostics($format, $yaml, yaml: true))->toHaveCount(1)
        ->and(EmittedSpecCheck::diagnostics($format, $yaml, yaml: true)[0]->message)->toContain('/paths');
})->with([
    'openapi-3.2' => ['openapi-3.2', '3.2.0'],
    'openapi-3.1' => ['openapi-3.1', '3.1.0'],
    'openapi-3.0' => ['openapi-3.0', '3.0.4'],
]);

/**
 * The one place the two carriers are treated differently, stated as a row so the asymmetry is a
 * decision rather than an oversight. `json_encode` is a total function into readable JSON, so
 * unreadable JSON stands in for an exception somebody can act on and is left alone. A third-party YAML
 * dumper whose round trip is the very thing under test is not, so bytes it wrote that will not read
 * back are reported — the loudest form of the defect this exists to catch.
 */
it('reports YAML it cannot read back, where it says nothing about unreadable JSON', function (): void {
    expect(EmittedSpecCheck::diagnostics('openapi-3.2', 'not json'))->toBe([]);

    $refused = EmittedSpecCheck::diagnostics('openapi-3.2', "info:\n  title: [unclosed\n", yaml: true);

    expect($refused)->toHaveCount(1)
        ->and($refused[0]->code)->toBe('document.openapi-invalid')
        ->and($refused[0]->severity)->toBe(Severity::Error)
        ->and($refused[0]->message)->toContain('cannot be read back');
});

/**
 * Validating must not change what is written. The check decodes the canonical serialisation and hands
 * it to opis, which applies schema `default`s INTO an instance unless told not to — so a check reading
 * the same graph the writer holds could quietly add members to the artifact.
 */
it('emits the same bytes whether or not the document is valid enough to check', function (string $format): void {
    $document = specCheckDocument();

    $first = Formats::emit($format, $document, new EmitOptions)->output;
    $second = Formats::emit($format, $document, new EmitOptions)->output;

    expect($second)->toBe($first)
        ->and($first)->not->toContain('jsonSchemaDialect');
})->with(specCheckFormats());

/**
 * OpenAPI 3.0's Schema Object is CLOSED, so one member it does not enumerate makes the whole artifact
 * invalid — a 3.0 consumer loses the document rather than the member. Anything the product's own
 * keyword tables recognise was already dropped; a keyword an overlay or an attribute wrote was not, and
 * that is what this holds. The 3.1 artifact keeps it, which is the reason dropping it is a downlevel
 * loss and not a correction.
 */
it('drops a keyword OpenAPI 3.0 does not define rather than emitting an invalid artifact', function (): void {
    $document = specCheckDocument(['components' => ['schemas' => ['Thing' => [
        'type' => 'object',
        'somethingNew' => 1,
        'x-vendor' => 'kept',
    ]]]]);

    $emit = static fn (string $format): array => [
        json_decode(Formats::emit($format, $document, new EmitOptions)->output, true, flags: JSON_THROW_ON_ERROR),
        specCheckFindings($format, $document),
    ];

    [$oas30, $findings30] = $emit('openapi-3.0');
    [$oas31] = $emit('openapi-3.1');

    expect($oas30['components']['schemas']['Thing'])->toBe(['type' => 'object', 'x-vendor' => 'kept'])
        ->and($findings30)->toBe([])
        ->and($oas31['components']['schemas']['Thing'])->toBe(['type' => 'object', 'somethingNew' => 1, 'x-vendor' => 'kept']);

    // And the reason the drop is sound rather than convenient: the member set is read off 3.0's own
    // meta-schema, and that object really is closed.
    expect(OpenApiMetaSchema::schemaMembers30())->not->toContain('somethingNew')
        ->toContain('nullable', 'type', 'properties')
        ->and(OpenApiMetaSchema::decode('openapi-3.0')->definitions->Schema->additionalProperties)->toBeFalse();
});
