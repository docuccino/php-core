<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;

/**
 * The deterministic diagnostic ordering used on every generateDocument() (and any
 * --embed-diagnostics payload). The composite key is [routeSignature, severity rank, code, message];
 * this is the byte-stability artifact the DiagnosticBag→DiagnosticCollector merge otherwise left
 * un-asserted (G3).
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

it('addAll appends an iterable in order', function (): void {
    $collector = new DiagnosticCollector;
    $collector->addAll([
        new Diagnostic(severity: Severity::Info, code: 'one', message: 'm'),
        new Diagnostic(severity: Severity::Info, code: 'two', message: 'm'),
    ]);

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $collector->all()))->toBe(['one', 'two']);
});
