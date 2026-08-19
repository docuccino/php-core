<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Pipeline\GenerationResult;

/**
 * The one written-down ordering of {@see Severity}. Both readers depend on it being total and stable:
 * `DiagnosticCollector::sorted()` for byte-stable output, `--fail-on` for its exit code.
 */
it('ranks every case, loudest first', function (Severity $severity, int $rank): void {
    expect($severity->rank())->toBe($rank);
})->with([
    'error' => [Severity::Error, 3],
    'warning' => [Severity::Warning, 2],
    'info' => [Severity::Info, 1],
    'hint' => [Severity::Hint, 0],
]);

it('gives every case a distinct rank, so the order is total', function (): void {
    $ranks = array_map(static fn (Severity $s): int => $s->rank(), Severity::cases());

    expect(array_unique($ranks))->toHaveCount(count(Severity::cases()));
});

it('answers atLeast across the whole matrix', function (Severity $severity, Severity $floor, bool $expected): void {
    expect($severity->atLeast($floor))->toBe($expected);
})->with(function (): Generator {
    // Loudest first — a severity clears every floor at or below its own rank and no other.
    $order = [Severity::Hint, Severity::Info, Severity::Warning, Severity::Error];

    foreach ($order as $i => $severity) {
        foreach ($order as $j => $floor) {
            yield sprintf('%s vs %s', $severity->value, $floor->value) => [$severity, $floor, $i >= $j];
        }
    }
});

it('reports whether anything reached a floor', function (Severity $floor, bool $expected): void {
    $result = new GenerationResult(
        new UirDocument('1.0.0', '3.2.0'),
        [new Diagnostic(severity: Severity::Info, code: 'a.code', message: 'm')],
    );

    expect($result->hasAtLeast($floor))->toBe($expected);
})->with([
    'hint floor catches an info' => [Severity::Hint, true],
    'info floor catches an info' => [Severity::Info, true],
    'warning floor does not' => [Severity::Warning, false],
    'error floor does not' => [Severity::Error, false],
]);

it('reports nothing at any floor when there are no diagnostics', function (Severity $floor): void {
    expect((new GenerationResult(new UirDocument('1.0.0', '3.2.0')))->hasAtLeast($floor))->toBeFalse();
})->with(Severity::cases());
