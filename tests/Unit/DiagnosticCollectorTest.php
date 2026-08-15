<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\Source;

/**
 * The deterministic diagnostic ordering used on every generateDocument() (and any
 * --embed-diagnostics payload). The composite key is [routeSignature, severity rank, code, message],
 * and byte stability is what it buys.
 */
it('sorts by routeSignature, then severity rank, then code, then message — insertion order untouched by all()', function (): void {
    $collector = new DiagnosticCollector;

    // Added deliberately out of final order.
    $collector->add(new Diagnostic(severity: Severity::Warning, code: 'b.code', message: 'm', routeSignature: 'POST /b'));
    $collector->add(new Diagnostic(severity: Severity::Error, code: 'a.code', message: 'm', routeSignature: 'POST /b'));
    $collector->add(new Diagnostic(severity: Severity::Hint, code: 'z.code', message: 'm'));
    $collector->add(new Diagnostic(severity: Severity::Info, code: 'a.code', message: 'm', routeSignature: 'POST /a'));

    $key = static fn (Diagnostic $d): string => ($d->routeSignature ?? '').'|'.$d->severity->name.'|'.$d->code;

    expect(array_map($key, $collector->sorted()))->toBe([
        '|Hint|z.code',          // an empty routeSignature sorts before any route
        'POST /a|Info|a.code',
        'POST /b|Error|a.code',  // within POST /b, Error outranks Warning
        'POST /b|Warning|b.code',
    ]);

    // all() is untouched insertion order.
    expect(array_map($key, $collector->all()))->toBe([
        'POST /b|Warning|b.code',
        'POST /b|Error|a.code',
        '|Hint|z.code',
        'POST /a|Info|a.code',
    ]);
});

it('ranks severities Error < Warning < Info < Hint', function (): void {
    $collector = new DiagnosticCollector;
    // Insert in a scrambled severity order under one route + identical code/message.
    foreach ([Severity::Hint, Severity::Info, Severity::Error, Severity::Warning] as $severity) {
        $collector->add(new Diagnostic(severity: $severity, code: 'same.code', message: 'm', routeSignature: 'GET /x'));
    }

    expect(array_map(static fn (Diagnostic $d): string => $d->severity->name, $collector->sorted()))
        ->toBe(['Error', 'Warning', 'Info', 'Hint']);
});

it('breaks ties by code then message with a stable order', function (): void {
    $collector = new DiagnosticCollector;
    $collector->add(new Diagnostic(severity: Severity::Error, code: 'same', message: 'zebra', routeSignature: 'GET /x'));
    $collector->add(new Diagnostic(severity: Severity::Error, code: 'same', message: 'apple', routeSignature: 'GET /x'));
    $collector->add(new Diagnostic(severity: Severity::Error, code: 'aaa', message: 'zzz', routeSignature: 'GET /x'));

    expect(array_map(static fn (Diagnostic $d): string => $d->code.':'.$d->message, $collector->sorted()))
        ->toBe(['aaa:zzz', 'same:apple', 'same:zebra']);
});

it('breaks a tie the message cannot, by source and then help', function (bool $reverse): void {
    // Two controllers each pointing #[DescriptionFromFile] outside the app say the same thing about a
    // different file, under no route signature at all — so everything the key looked at agreed and the
    // pair came out in discovery order. The key runs on to what actually differs.
    $collector = new DiagnosticCollector;
    $diagnostics = [
        new Diagnostic(Severity::Error, 'same', 'm', new Source('src/Zeta.php', 12), null, 'help'),
        new Diagnostic(Severity::Error, 'same', 'm', new Source('src/Alpha.php', 9), null, 'help'),
        new Diagnostic(Severity::Error, 'same', 'm', new Source('src/Alpha.php', 9), null, 'a-help'),
        new Diagnostic(Severity::Error, 'same', 'm', new Source('src/Alpha.php'), null, 'help'),
    ];
    $collector->addAll($reverse ? array_reverse($diagnostics) : $diagnostics);

    expect(array_map(
        static fn (Diagnostic $d): string => ($d->source?->file ?? '').':'.($d->source?->line ?? '-').':'.$d->help,
        $collector->sorted(),
    ))->toBe([
        // A source with no line sorts before one that has one, rather than tying with all of them.
        'src/Alpha.php:-:help',
        'src/Alpha.php:9:a-help',
        'src/Alpha.php:9:help',
        'src/Zeta.php:12:help',
    ]);
})->with([false, true]);

it('addAll appends an iterable in order', function (): void {
    $collector = new DiagnosticCollector;
    $collector->addAll([
        new Diagnostic(severity: Severity::Info, code: 'one', message: 'm'),
        new Diagnostic(severity: Severity::Info, code: 'two', message: 'm'),
    ]);

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $collector->all()))->toBe(['one', 'two']);
});
