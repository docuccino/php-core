<?php

declare(strict_types=1);

use Docuccino\Core\Document\UseSites;
use Docuccino\Core\Reader\DocumentFinding;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\FindingSource;
use Docuccino\Core\SpecValidation\Validator;
use Docuccino\Core\Support\JsonValue;

/*
 * Where a read document fails its specifications: the OpenAPI meta-schema for the version it declares,
 * and — for a full document — the extension schema for the UIR version it was written to, never another.
 */

it('finds nothing wrong with a golden of each kind', function (string $golden): void {
    $validation = (new DocumentReader)->read((string) file_get_contents(golden($golden)))->validate();

    expect(array_map(strval(...), $validation->findings))->toBe([])
        ->and($validation->isValid())->toBeTrue()
        ->and($validation->isTruncated())->toBeFalse();
})->with([
    'a full artifact' => ['workbench-content.uir.json'],
    'a full artifact with diagnostics' => ['handler-diagnostics.uir.json'],
    'an export with ids kept' => ['versioned-forms.openapi32.ids.json'],
    'a 3.1 export' => ['tagged-union.openapi31.json'],
    'a 3.0 export' => ['tagged-union.openapi30.json'],
]);

it('holds each document to the meta-schema of the version it declares', function (string $openapi, array $members, ?string $pointer): void {
    // Each member is valid from one version on and refused before it, so a reader validating every
    // document as one version gets a row here wrong.
    $document = ['openapi' => $openapi, 'info' => ['title' => 'Orders', 'version' => '1'], 'paths' => new stdClass] + $members;

    $findings = (new DocumentReader)->read((string) json_encode($document))->validate()->findings;

    expect($findings[0]->pointer ?? null)->toBe($pointer)
        ->and($findings[0]->source ?? FindingSource::OpenApi)->toBe(FindingSource::OpenApi);
})->with([
    'a type list in 3.0' => ['3.0.4', ['components' => ['schemas' => ['Order' => ['type' => ['string', 'null']]]]], '/components/schemas/Order/type'],
    'a type list in 3.1' => ['3.1.1', ['components' => ['schemas' => ['Order' => ['type' => ['string', 'null']]]]], null],
    // 3.0's meta-schema closes the root object, so it reports the member at the root and names it.
    'webhooks in 3.0' => ['3.0.4', ['webhooks' => new stdClass], '/'],
    'webhooks in 3.1' => ['3.1.1', ['webhooks' => new stdClass], null],
    '$self in 3.1' => ['3.1.1', ['$self' => 'https://example.com/openapi'], '/$self'],
    '$self in 3.2' => ['3.2.0', ['$self' => 'https://example.com/openapi'], null],
]);

it('reports a version it carries no meta-schema for, rather than calling the document valid', function (array $declared, string $pointer, string $message): void {
    $validation = (new DocumentReader)->read((string) json_encode($declared + ['info' => ['title' => 'Orders', 'version' => '1'], 'paths' => new stdClass]))->validate();

    expect($validation->isValid())->toBeFalse()
        ->and(array_map(strval(...), $validation->findings))->toBe(["[openapi] {$pointer} openapi: {$message}"]);
})->with([
    'a future version' => [['openapi' => '4.0.0'], '/openapi', 'The document declares OpenAPI "4.0.0", and no meta-schema for it ships with this package (openapi-3.2, openapi-3.1, openapi-3.0); it was not validated'],
    'Swagger 2.0' => [['swagger' => '2.0'], '/', 'The document declares no OpenAPI version, and no meta-schema for it ships with this package (openapi-3.2, openapi-3.1, openapi-3.0); it was not validated'],
]);

it('holds a full document\'s extension to the schema of the UIR version it was written to', function (): void {
    $document = JsonValue::decode((string) file_get_contents(golden('workbench-content.uir.json')));
    expect($document)->toBeArray();

    /** @var array<string, mixed> $document */
    $document['paths']['/api/forms']['get']['x-docuccino']['owner'] = 'billing';

    $findings = (new DocumentReader)->read((string) json_encode($document))->validate()->findingsFrom(FindingSource::Extension);

    expect(array_map(strval(...), $findings))->toBe([
        '[extension] /paths/~1api~1forms/get/x-docuccino additionalProperties: Additional object properties are not allowed: owner (schema /$defs/extension/$defs/operationNode)',
    ]);
});

it('reads a UIR 2.0 artifact against the 2.0 schema, which is where its use sites are valid', function (): void {
    // 2.0 wrote a use's node beside its `$ref`; 2.1's schema refuses that position and 2.0's accepts it.
    // Checked against 2.1, every use would be a finding the document's author never had.
    $document = UseSites::lower(JsonValue::decode((string) file_get_contents(golden('workbench-content.uir.json'))));
    $document['x-docuccino']['generator']['specVersion'] = '2.0.0';
    $document['x-docuccino']['generator']['schema'] = 'https://spec.docuccino.app/uir/2.0/schema.json';

    $validation = (new DocumentReader)->read((string) json_encode($document))->validate();

    expect($validation->findingsFrom(FindingSource::Extension))->toBe([])
        // …while OpenAPI itself still refuses the extension beside a Reference Object, which is a fact.
        ->and($validation->findingsFrom(FindingSource::OpenApi))->not->toBeEmpty()
        ->and($validation->findingsFrom(FindingSource::OpenApi)[0]->message)->toBe('a Reference Object cannot carry "x-docuccino" beside its $ref');
});

it('reads a pre-2.0 artifact against the 1.x schema it was written to', function (): void {
    $document = JsonValue::decode((string) file_get_contents(golden('handler-diagnostics.uir.json')));
    expect($document)->toBeArray();

    /** @var array<string, mixed> $document */
    $document = ['$schema' => 'https://spec.docuccino.app/uir/1.1/schema.json', 'uir' => '1.1.0'] + $document;
    unset($document['x-docuccino']['generator']['specVersion'], $document['x-docuccino']['generator']['schema']);

    $validation = (new DocumentReader)->read((string) json_encode($document))->validate();

    // The root members 2.0 retired are OpenAPI findings; under 1.1 they are required, so the extension
    // half complains only about what 1.1 did not know — never that its schema is missing.
    expect(array_map(static fn (DocumentFinding $finding): string => $finding->pointer, $validation->findingsFrom(FindingSource::OpenApi)))->toBe(['/$schema', '/uir'])
        ->and(array_map(strval(...), $validation->findingsFrom(FindingSource::Extension)))
        ->toBe(['[extension] /x-docuccino/generator required: The required properties (specVersion) are missing (schema /$defs/generator)']);
});

it('reports a UIR version no schema ships for, rather than validating it against another', function (?string $specVersion, string $message): void {
    $document = JsonValue::decode((string) file_get_contents(golden('handler-diagnostics.uir.json')));
    expect($document)->toBeArray();

    /** @var array<string, mixed> $document */
    if ($specVersion === null) {
        unset($document['x-docuccino']['generator']['specVersion']);
    } else {
        $document['x-docuccino']['generator']['specVersion'] = $specVersion;
    }

    $validation = (new DocumentReader)->read((string) json_encode($document))->validate();

    expect($validation->isValid())->toBeFalse()
        ->and(array_map(strval(...), $validation->findings))->toBe(["[extension] /x-docuccino/generator specVersion: {$message}"]);
})->with([
    'a later minor' => ['2.9.0', 'The document is written to UIR "2.9.0", and no schema for it ships with this package (1.0, 1.1, 2.0, 2.1); the extension was not validated'],
    'a later major' => ['3.0.0', 'The document is written to UIR "3.0.0", and no schema for it ships with this package (1.0, 1.1, 2.0, 2.1); the extension was not validated'],
    'not a version' => ['current', 'The document is written to UIR "current", and no schema for it ships with this package (1.0, 1.1, 2.0, 2.1); the extension was not validated'],
    'none at all' => [null, 'The document states no UIR version, and no schema for it ships with this package (1.0, 1.1, 2.0, 2.1); the extension was not validated'],
]);

it('lists the schema versions that ship, and every one of them is in the published spec', function (): void {
    $published = array_map(static fn (string $path): string => basename(dirname($path)), (array) glob(dirname(__DIR__, 5).'/spec/uir/*/schema.json'));
    usort($published, static fn (string $a, string $b): int => version_compare($a, $b));

    expect(Validator::vendoredVersions())->toBe($published)
        ->and($published)->toContain('2.1');
});

it('cuts the findings at the limit asked for, saying so', function (): void {
    $document = ['openapi' => '3.1.0', 'info' => ['title' => 'Orders', 'version' => '1'], 'paths' => ['/a' => ['get' => 1], '/b' => ['get' => 2], '/c' => ['get' => 3], '/d' => ['get' => 4]]];

    $read = (new DocumentReader)->read((string) json_encode($document));
    $all = $read->validate()->findings;
    $cut = $read->validate(2);

    expect(count($all))->toBeGreaterThan(2)
        ->and($cut->isValid())->toBeFalse()
        ->and($cut->isTruncated())->toBeTrue()
        ->and($cut->findings)->toHaveCount(3)
        ->and(array_slice($cut->findings, 0, 2))->toEqual(array_slice($all, 0, 2))
        ->and((string) $cut->findings[2])->toBe('[openapi] /: more than 2 findings; the first 2 are shown')
        ->and($read->validate(count($all))->isTruncated())->toBeFalse();
});

it('refuses a limit that would show nothing', function (): void {
    $read = (new DocumentReader)->read('{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":{}}');

    expect(fn (): mixed => $read->validate(0))->toThrow(InvalidArgumentException::class, 'A validation reports at least one finding; 0 asks for none.');
});

it('hands back the finding a spec check made, with its source beside it', function (): void {
    $findings = (new DocumentReader)->read('{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":[]}')->validate()->findings;

    expect($findings[0]->finding()->pointer)->toBe('/paths')
        ->and((string) $findings[0])->toBe('[openapi] '.$findings[0]->finding());
});
