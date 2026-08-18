<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\ReportingEmitter;
use Docuccino\Core\Emit\UirEmitter;

/**
 * The format table: every entry resolves to the emitter that claims that id, and an unknown id
 * degrades predictably rather than silently producing something in a format nobody asked for.
 */

/** Every table entry: id, whether it serialises YAML, whether the viewer can serve it. */
dataset('formats', [
    'openapi-3.2' => ['openapi-3.2', true, true, '"openapi": "3.2.0"'],
    'openapi-3.1' => ['openapi-3.1', true, true, '"openapi": "3.1.1"'],
    'openapi-3.0' => ['openapi-3.0', true, true, '"openapi": "3.0.4"'],
    'uir' => ['uir', false, true, '"uir":'],
]);

it('lists every known format', function (string $format): void {
    expect(Formats::ids())->toContain($format)
        ->and(Formats::supports($format))->toBeTrue();
})->with('formats');

it('emits each format through the emitter that claims that id', function (string $format, bool $yaml, bool $servable, string $marker): void {
    $result = Formats::emit($format, UirDocument::fromArray(workedExample()), new EmitOptions);

    expect($result->output)->toContain($marker)
        ->and(Formats::serialisesYaml($format))->toBe($yaml)
        ->and(in_array($format, Formats::viewerPreference(), true))->toBe($servable);
})->with('formats');

it('agrees with each emitter about the id it answers to', function (string $format): void {
    // The table is the only place a format id is written down; an emitter renaming itself must not be
    // able to drift from the id the CLI and config validate against.
    $result = Formats::emit($format, UirDocument::fromArray(workedExample()), new EmitOptions);
    expect($result->output)->not->toBeEmpty();

    $emitters = [
        new OpenApi32Emitter,
        new OpenApi31DownlevelEmitter,
        new OpenApi30DownlevelEmitter,
        new UirEmitter,
    ];

    $claimed = array_map(static fn (ReportingEmitter $e): string => $e->format(), $emitters);
    expect($claimed)->toContain($format);
})->with('formats');

it('serialises YAML only for the formats that have a YAML form', function (string $format, bool $yaml): void {
    $result = Formats::emit($format, UirDocument::fromArray(workedExample()), (new EmitOptions)->withYaml());

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

it('carries no shared default for provenance, so each format keeps its own', function (): void {
    // UirEmitter defaults to Full and the OpenAPI emitters to None; Formats::emit() takes options
    // explicitly so neither default can leak into the other.
    $document = UirDocument::fromArray(workedExample());

    expect(Formats::emit('uir', $document, new EmitOptions(provenance: ProvenanceLevel::Full))->output)->toContain('"provenance"')
        ->and(Formats::emit('uir', $document, new EmitOptions(provenance: ProvenanceLevel::None))->output)->not->toContain('"provenance"');
});
