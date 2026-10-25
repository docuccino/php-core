<?php

declare(strict_types=1);

use Docuccino\Core\Reader\Capability;
use Docuccino\Core\Reader\DocumentFinding;
use Docuccino\Core\Reader\DocumentGenerator;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ExtensionMember;
use Docuccino\Core\Reader\FindingSource;
use Docuccino\Core\Reader\NodeKind;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\Syntax;
use Docuccino\Core\SpecValidation\Validator;
use Docuccino\Core\Support\JsonValue;

/*
 * What a document states about itself: its OpenAPI version, how much of the extension it carries, who
 * wrote it, and whether it predates UIR 2.0.
 */

it('reads each capability off the goldens that stand for it', function (string $golden, Capability $capability, ?string $format): void {
    $inspection = (new DocumentReader)->read((string) file_get_contents(golden($golden)))->inspect();

    expect($inspection->capability)->toBe($capability)
        ->and($inspection->format)->toBe($format)
        ->and($inspection->legacy)->toBeFalse();
})->with([
    'a full artifact' => ['workbench-content.uir.json', Capability::Full, 'openapi-3.2'],
    'an export with ids kept' => ['versioned-forms.openapi32.ids.json', Capability::Identities, 'openapi-3.2'],
    'a 3.1 export' => ['tagged-union.openapi31.json', Capability::Plain, 'openapi-3.1'],
    'a 3.0 export' => ['tagged-union.openapi30.json', Capability::Plain, 'openapi-3.0'],
]);

it('calls a document full on a root extension object alone, however little it holds', function (): void {
    $inspection = (new DocumentReader)->read('{"openapi":"3.2.0","info":{"title":"Orders","version":"1"},"paths":{},"x-docuccino":{}}')->inspect();

    expect($inspection->capability)->toBe(Capability::Full)
        ->and($inspection->generator)->toBeNull()
        ->and($inspection->extensions)->toBe([]);
});

it('does not call a root extension that is no object full', function (): void {
    $inspection = (new DocumentReader)->read('{"openapi":"3.2.0","info":{"title":"Orders","version":"1"},"paths":{},"x-docuccino":[]}')->inspect();

    expect($inspection->capability)->toBe(Capability::Plain);
});

it('maps the declared OpenAPI version to the format whose meta-schema reads it', function (?string $openapi, ?string $format): void {
    $document = ['info' => ['title' => 'Orders', 'version' => '1'], 'paths' => new stdClass];
    if ($openapi !== null) {
        $document = ['openapi' => $openapi] + $document;
    }

    $inspection = (new DocumentReader)->read((string) json_encode($document))->inspect();

    expect($inspection->openapi)->toBe($openapi)
        ->and($inspection->format)->toBe($format);
})->with([
    '3.0.4' => ['3.0.4', 'openapi-3.0'],
    '3.1.1' => ['3.1.1', 'openapi-3.1'],
    '3.2.0' => ['3.2.0', 'openapi-3.2'],
    'a pre-release' => ['3.2.0-rc1', 'openapi-3.2'],
    'no patch' => ['3.1', 'openapi-3.1'],
    'a minor no meta-schema ships for' => ['3.3.0', null],
    'a major no meta-schema ships for' => ['4.0.0', null],
    'Swagger\'s version, written in the wrong member' => ['2.0', null],
    'not a version' => ['latest', null],
    'nothing' => [null, null],
]);

it('lists the extension members a document carries, wherever they sit', function (): void {
    $content = (new DocumentReader)->read((string) file_get_contents(golden('workbench-content.uir.json')))->inspect();
    $diagnostics = (new DocumentReader)->read((string) file_get_contents(golden('handler-diagnostics.uir.json')))->inspect();
    $mocked = (new DocumentReader)->read((string) json_encode([
        'openapi' => '3.2.0',
        'info' => ['title' => 'Orders', 'version' => '1'],
        'paths' => new stdClass,
        'components' => ['schemas' => ['Order' => ['type' => 'object', 'x-docuccino' => ['mock' => ['faker' => 'uuid']]]]],
        'x-docuccino' => ['document' => ['id' => 'doc:default']],
    ]))->inspect();

    expect($content->extensions)->toBe([ExtensionMember::Provenance, ExtensionMember::Content, ExtensionMember::Workflows, ExtensionMember::Uses])
        ->and($content->carries(ExtensionMember::Uses))->toBeTrue()
        ->and($content->carries(ExtensionMember::Diagnostics))->toBeFalse()
        ->and($diagnostics->carries(ExtensionMember::Diagnostics))->toBeTrue()
        ->and($mocked->extensions)->toBe([ExtensionMember::Mock]);
});

it('names every extension member the published schema puts on a node or the root', function (): void {
    // The enum is a hand-kept list of the schema's optional members, so it is held to the schema: every
    // member of the root extension and of an operation's node, except the identifying ones every node has.
    $schema = JsonValue::decode((string) file_get_contents(dirname(__DIR__, 5).'/spec/uir/2.1/extension.schema.json'));
    expect($schema)->toBeArray();

    /** @var array{properties: array<string, mixed>, '$defs': array{operationNode: array{properties: array<string, mixed>}}} $schema */
    $published = array_diff(
        [...array_keys($schema['properties']), ...array_keys($schema['$defs']['operationNode']['properties'])],
        ['document', 'generator', 'id', 'facts'],
    );
    sort($published);

    $named = array_map(static fn (ExtensionMember $member): string => $member->value, ExtensionMember::cases());
    sort($named);

    expect($named)->toBe(array_values(array_unique($published)));
});

it('reads the generator a full document names, and nothing it does not state as text', function (): void {
    $golden = (new DocumentReader)->read((string) file_get_contents(golden('workbench-content.uir.json')))->inspect();
    $partial = (new DocumentReader)->read('{"openapi":"3.2.0","info":{"title":"Orders","version":"1"},"paths":{},"x-docuccino":{"generator":{"name":"acme/generator","version":7}}}')->inspect();

    expect($golden->generator)->toEqual(new DocumentGenerator('docuccino/laravel', '0.9.1', '2.1.0', 'https://spec.docuccino.app/uir/2.1/schema.json'))
        ->and($partial->generator)->toEqual(new DocumentGenerator('acme/generator', null, null, null));
});

it('marks an artifact written before UIR 2.0 as legacy', function (array $root, array $generator, bool $legacy): void {
    $document = $root + [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Orders', 'version' => '1'],
        'paths' => new stdClass,
        'x-docuccino' => ['generator' => $generator + ['name' => 'docuccino/laravel', 'version' => '0.5.0']],
    ];

    expect((new DocumentReader)->read((string) json_encode($document))->inspect()->legacy)->toBe($legacy);
})->with([
    'root uir and $schema' => [['$schema' => 'https://spec.docuccino.app/uir/1.1/schema.json', 'uir' => '1.1.0'], ['specVersion' => '1.1.0'], true],
    'root uir alone' => [['uir' => '1.0.0'], [], true],
    'root $schema alone' => [['$schema' => 'https://spec.docuccino.app/uir/1.1/schema.json'], [], true],
    'a 1.x spec version and no root members' => [[], ['specVersion' => '1.1.0'], true],
    'a 2.0 spec version' => [[], ['specVersion' => '2.0.0'], false],
    'a 2.1 spec version' => [[], ['specVersion' => '2.1.0'], false],
    'no spec version at all' => [[], [], false],
]);

it('writes the generator as values a store can hold, one member per property', function (): void {
    $generator = new DocumentGenerator('docuccino/laravel', '0.9.1', '2.1.0', null);

    expect($generator->toArray())->toBe(['name' => 'docuccino/laravel', 'version' => '0.9.1', 'specVersion' => '2.1.0', 'schema' => null])
        ->and(array_keys($generator->toArray()))->toBe(array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(DocumentGenerator::class, '__construct'))->getParameters(),
        ));
});

it('reads the UIR version a document is written to as the major.minor its schema is published under', function (array $root, mixed $specVersion, ?string $uirVersion): void {
    $generator = ['name' => 'docuccino/laravel', 'version' => '0.9.1'] + ($specVersion === null ? [] : ['specVersion' => $specVersion]);
    $read = (new DocumentReader)->read((string) json_encode($root + [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Orders', 'version' => '1'],
        'paths' => new stdClass,
        'x-docuccino' => ['generator' => $generator],
    ]));

    $unvalidated = array_filter(
        $read->validate()->findingsFrom(FindingSource::Extension),
        static fn (DocumentFinding $finding): bool => str_contains($finding->message, 'the extension was not validated'),
    );

    // The version validate() picks the schema by: where it reads none, or one no schema ships for,
    // the extension goes unvalidated and says so.
    expect($read->inspect()->uirVersion)->toBe($uirVersion)
        ->and($unvalidated === [])->toBe($uirVersion !== null && in_array($uirVersion, Validator::vendoredVersions(), true));
})->with([
    'a full version' => [[], '2.1.0', '2.1'],
    'a patch past it' => [[], '2.0.7', '2.0'],
    'major.minor alone' => [[], '2.1', '2.1'],
    'two-digit parts' => [[], '10.12.3', '10.12'],
    'a version no schema ships for' => [[], '2.9.0', '2.9'],
    'a major alone' => [[], '2', null],
    'a pre-release suffix' => [[], '2.1-beta', null],
    'not a version' => [[], 'current', null],
    'not text' => [[], 2.1, null],
    'none at all' => [[], null, null],
    'the root uir of an artifact before 2.0' => [['uir' => '1.0.0'], null, '1.0'],
]);

it('publishes every reader enum as values a caller may store, and changes none of them quietly', function (): void {
    $published = [
        Capability::class => ['plain', 'identities', 'full'],
        ExtensionMember::class => ['provenance', 'content', 'mock', 'workflows', 'diagnostics', 'uses'],
        FindingSource::class => ['openapi', 'extension'],
        NodeKind::class => ['operation', 'parameter', 'response', 'schema', 'page', 'workflow'],
        ReadFailure::class => ['encoding', 'syntax', 'not-an-object', 'alias-expansion', 'unrepresentable', 'too-deep', 'node-expansion'],
        Syntax::class => ['json', 'yaml'],
    ];

    // Read off the directory, so an enum the reader adds tomorrow is a row owed rather than one skipped.
    $declared = [];
    foreach ((array) glob(dirname(__DIR__, 3).'/src/Reader/*.php') as $file) {
        $class = 'Docuccino\Core\Reader\\'.basename((string) $file, '.php');
        if (enum_exists($class) && (new ReflectionEnum($class))->isBacked()) {
            $declared[] = $class;
        }
    }
    sort($declared);

    $listed = array_keys($published);
    sort($listed);

    expect($declared)->toBe($listed)
        ->and(count($declared))->toBeGreaterThanOrEqual(5);

    foreach ($published as $enum => $values) {
        expect(array_map(static fn (BackedEnum $case): string|int => $case->value, $enum::cases()))->toBe($values);
    }
});
