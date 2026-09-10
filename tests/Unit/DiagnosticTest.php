<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\Source;

/*
 * `Diagnostic` owns making its own text safe to print, so these rows are about what a PRODUCER can and
 * cannot do — not about any one producer. The relevant producer is the one nobody has written yet.
 */

it('makes safe every field it states, whatever the producer handed it', function (string $field, string $raw, string $safe): void {
    $diagnostic = new Diagnostic(
        severity: Severity::Warning,
        code: $field === 'code' ? $raw : 'demo.code',
        message: $field === 'message' ? $raw : 'fine',
        help: $field === 'help' ? $raw : null,
    );

    expect($diagnostic->{$field})->toBe($safe);
})->with([
    'code' => ['code', "sneaky\u{202E}edoc", 'sneaky\u{202E}edoc'],
    'message' => ['message', "GET /forms\x1B[31m", 'GET /forms\x1B[31m'],
    'help' => ['help', "run \x1B[31mthis\u{2028}", 'run \x1B[31mthis\u{2028}'],
]);

it('keeps a line break in help, because a console writer reads it as layout', function (): void {
    // The `\r\n` normalises so that one line ending cannot read as two, which is what an indented-line
    // count is counted against.
    $diagnostic = new Diagnostic(Severity::Info, 'demo.code', 'fine', help: "one\r\ntwo\rthree\nfour\x07");

    expect($diagnostic->help)->toBe("one\ntwo\nthree\nfour".'\x07');
});

it('leaves the route signature and the source as it was given them', function (): void {
    // Both are compared against, or resolved from, something computed outside a diagnostic — a live
    // route's signature, the provenance trail's own source. Escaping either here would make the two
    // sides disagree, so a signature is owed neutralising where it is minted instead.
    $diagnostic = new Diagnostic(
        severity: Severity::Warning,
        code: 'demo.code',
        message: 'fine',
        source: new Source("app/Http/Odd\u{202E}.php", 3, 'index'),
        routeSignature: "GET api/odd\u{202E}",
    );

    expect($diagnostic->routeSignature)->toBe("GET api/odd\u{202E}")
        ->and($diagnostic->source?->file)->toBe("app/Http/Odd\u{202E}.php");
});

it('refuses a producer that tries to write the text past the constructor', function (): void {
    // The claim is that construction is the ONLY way in, so here is the code that would get round it.
    $diagnostic = new Diagnostic(Severity::Warning, 'demo.code', 'fine');

    expect(static fn () => $diagnostic->message = "GET /forms\x1B[31m")
        ->toThrow(Error::class, 'Cannot modify readonly property');
});

it('makes safe a diagnostic hydrated out of an artifact as readily as one just built', function (): void {
    // `fromArray()` is the warm fragment-cache path AND how a committed artifact's own diagnostics come
    // back, so text that never met a producer in this process arrives the same way.
    $hydrated = Diagnostic::fromArray([
        'severity' => 'error',
        'code' => "hydrated\u{202E}",
        'message' => "read from \x1B[31man artifact",
        'help' => "fix \u{009B}31mit",
    ]);

    expect($hydrated->code)->toBe('hydrated\u{202E}')
        ->and($hydrated->message)->toBe('read from \x1B[31man artifact')
        ->and($hydrated->help)->toBe('fix \u{009B}31mit');
});

it('says the same thing however many times it is round-tripped', function (): void {
    // What lets the escaping sit at construction rather than at every reader: a warm build rehydrates,
    // and a rehydrated diagnostic must not gain a layer of escapes per rebuild.
    $once = new Diagnostic(Severity::Warning, "c\u{202E}", "m\x1B[31m", help: "h\u{2028}\nsecond");
    $twice = Diagnostic::fromArray($once->toArray());
    $thrice = Diagnostic::fromArray($twice->toArray());

    expect($twice->toArray())->toBe($once->toArray())
        ->and($thrice->toArray())->toBe($once->toArray());
});
