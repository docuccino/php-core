<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\Arazzo\ArazzoEmitter;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\Postman\CollectionEmitter;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\ReportingEmitter;
use Docuccino\Core\Emit\UirEmitter;

/**
 * The format table: every entry resolves to the emitter that claims that id, and an unknown id
 * degrades predictably rather than silently producing something in a format nobody asked for.
 */

/**
 * Every table entry: id, whether it serialises YAML, whether the viewer can serve it, a marker its
 * output must carry, and the fixture that HAS something for it to say. The last column is why the
 * dataset was short by two for as long as it was: the worked example declares no workflows, so an
 * `arazzo` row against it would assert an empty file rather than a description.
 */
function formatRows(): array
{
    return [
        'openapi-3.2' => ['openapi-3.2', true, true, '"openapi": "3.2.0"', 'worked-example.json'],
        'openapi-3.1' => ['openapi-3.1', true, true, '"openapi": "3.1.1"', 'worked-example.json'],
        'openapi-3.0' => ['openapi-3.0', true, true, '"openapi": "3.0.4"', 'worked-example.json'],
        'uir' => ['uir', false, true, '"uir":', 'worked-example.json'],
        'postman' => ['postman', false, false, 'schema.getpostman.com', 'worked-example.json'],
        'arazzo' => ['arazzo', true, false, '"arazzo": "1.1.0"', 'workflows.uir.json'],
    ];
}

dataset('formats', formatRows());

/**
 * The document a format row is exercised against.
 *
 * @return array<string, mixed>
 */
function formatFixture(string $name): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/'.$name),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $decoded;
}

/*
 * The guard the dataset cannot be. A hand-maintained "every table entry" proves only the rows it
 * lists, and this one was short by two — `postman` since it shipped and `arazzo` the day it landed —
 * with every assertion above passing. Derived from the table, so a format added tomorrow fails here
 * until it has a row.
 */
it('exercises every format the table knows', function (): void {
    $covered = array_map(static fn (array $row): string => $row[0], array_values(formatRows()));

    expect(array_values(array_diff(Formats::ids(), $covered)))->toBe([], 'formats with no dataset row')
        ->and(array_values(array_diff($covered, Formats::ids())))->toBe([], 'rows for formats the table has not got');
});

it('lists every known format', function (string $format): void {
    expect(Formats::ids())->toContain($format)
        ->and(Formats::supports($format))->toBeTrue();
})->with('formats');

it('emits each format through the emitter that claims that id', function (string $format, bool $yaml, bool $servable, string $marker, string $fixture): void {
    $result = Formats::emit($format, UirDocument::fromArray(formatFixture($fixture)), new EmitOptions);

    expect($result->output)->toContain($marker)
        ->and(Formats::serialisesYaml($format))->toBe($yaml)
        ->and(in_array($format, Formats::viewerPreference(), true))->toBe($servable);
})->with('formats');

it('agrees with each emitter about the id it answers to', function (string $format, bool $yaml, bool $servable, string $marker, string $fixture): void {
    // The table is the only place a format id is written down; an emitter renaming itself must not be
    // able to drift from the id the CLI and config validate against.
    $result = Formats::emit($format, UirDocument::fromArray(formatFixture($fixture)), new EmitOptions);
    expect($result->output)->not->toBeEmpty();

    $emitters = [
        new OpenApi32Emitter,
        new OpenApi31DownlevelEmitter,
        new OpenApi30DownlevelEmitter,
        new UirEmitter,
        new CollectionEmitter,
        new ArazzoEmitter,
    ];

    $claimed = array_map(static fn (ReportingEmitter $e): string => $e->format(), $emitters);
    expect($claimed)->toContain($format);
})->with('formats');

it('serialises YAML only for the formats that have a YAML form', function (string $format, bool $yaml, bool $servable, string $marker, string $fixture): void {
    $result = Formats::emit($format, UirDocument::fromArray(formatFixture($fixture)), (new EmitOptions)->withYaml());

    // UIR ignores the flag entirely and stays canonical JSON; the OpenAPI formats honour it.
    expect(str_starts_with(trim($result->output), '{'))->toBe(! $yaml);
})->with('formats');

it('reports what a downlevel could not carry, and nothing for a lossless format', function (): void {
    $document = UirDocument::fromArray(kitchenSink());

    expect(Formats::emit('openapi-3.2', $document, new EmitOptions)->report->isEmpty())->toBeTrue()
        ->and(Formats::emit('uir', $document, new EmitOptions)->report->isEmpty())->toBeTrue()
        ->and(Formats::emit('openapi-3.0', $document, new EmitOptions)->report->isEmpty())->toBeFalse();
});

it('throws on an unknown format rather than falling back to a default', function (): void {
    // Silently emitting 3.2 here would write an artifact in a format the caller never asked for.
    expect(Formats::supports('swagger-2.0'))->toBeFalse()
        ->and(Formats::serialisesYaml('swagger-2.0'))->toBeFalse()
        ->and(fn () => Formats::emit('swagger-2.0', UirDocument::fromArray(workedExample()), new EmitOptions))
        ->toThrow(InvalidArgumentException::class, 'swagger-2.0');
});

it('prefers the most faithful format the viewer can serve, in table order', function (): void {
    expect(Formats::viewerPreference())->toBe(['openapi-3.2', 'openapi-3.1', 'openapi-3.0', 'uir'])
        ->and(Formats::DEFAULT)->toBe('openapi-3.2');
});

it('prefers UIR for a contract, and names only formats the table knows', function (): void {
    // Its own order, not the table's: the viewer wants the most faithful OpenAPI, the contract
    // assertions want the one artifact that carries provenance.
    expect(Formats::contractPreference())->toBe(['uir', 'openapi-3.2', 'openapi-3.1', 'openapi-3.0']);

    foreach (Formats::contractPreference() as $format) {
        expect(Formats::supports($format))->toBeTrue();
    }
});

it('leaves a collection out of the contract preference, being a client and not a contract', function (): void {
    expect(Formats::contractPreference())->not->toContain('postman')
        ->and(Formats::supports('postman'))->toBeTrue();
});

it('carries no shared default for provenance, so each format keeps its own', function (): void {
    // UirEmitter defaults to Full and the OpenAPI emitters to None; Formats::emit() takes options
    // explicitly so neither default can leak into the other.
    $document = UirDocument::fromArray(workedExample());

    expect(Formats::emit('uir', $document, new EmitOptions(provenance: ProvenanceLevel::Full))->output)->toContain('"provenance"')
        ->and(Formats::emit('uir', $document, new EmitOptions(provenance: ProvenanceLevel::None))->output)->not->toContain('"provenance"');
});

/**
 * The column saying which formats hold their own output to a published specification, asked of the
 * BEHAVIOUR rather than of the table that claims it. A caller — `docuccino:validate` is the one that
 * needs it — has to be able to tell a clean check from one nobody ran, and a table column agreeing
 * only with itself would let a format stop being checked with the row still reading `true`.
 *
 * Driven over every id in the table, so a format added without a meta-schema behind it fails here
 * rather than quietly answering "valid" for an artifact nothing looked at.
 */
it('reports an invalid artifact for exactly the formats the table says it checks', function (string $format): void {
    $document = workedExample();
    // Accepted by every meta-schema as an ordinary string member, so the reference walk inside the
    // check is the only thing that can see it — which makes this a probe of the check and nothing else.
    $document['components']['schemas']['Dangling'] = ['$ref' => '#/components/schemas/NobodyDefinesThis'];

    $report = Formats::emit($format, UirDocument::fromArray($document), new EmitOptions)->report;

    $found = array_filter(
        $report->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'document.openapi-invalid',
    );

    expect($found !== [])->toBe(Formats::checksEmittedArtifact($format));
})->with(fn (): array => Formats::ids());

/**
 * And the two counts behind that row, so neither half of it can go vacuous: a table where nothing is
 * checked would satisfy every row above, and so would one where everything is.
 */
it('splits the formats into the ones with a published schema behind them and the ones without', function (): void {
    $checked = array_values(array_filter(Formats::ids(), Formats::checksEmittedArtifact(...)));
    $unchecked = array_values(array_filter(Formats::ids(), static fn (string $f): bool => ! Formats::checksEmittedArtifact($f)));

    expect($checked)->toBe(['openapi-3.2', 'openapi-3.1', 'openapi-3.0'])
        // Three different reasons, and the column is worth having because they are different. UIR
        // answers to its own schema on every build, before any emission. A Postman collection has no
        // published specification to be held to at all. An Arazzo description HAS one and is held to it
        // in the suite rather than at run time: the runtime check reads `OpenApiMetaSchema`, whose
        // traversal and whose diagnostic are both OpenAPI-shaped, so saying `true` here would mean
        // reporting an Arazzo failure as an OpenAPI one.
        ->and($unchecked)->toBe(['uir', 'postman', 'arazzo'])
        ->and(Formats::checksEmittedArtifact('swagger-2.0'))->toBeFalse();
});
