<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\AcceptedCodes;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * The rules acceptance is made of. The one that cannot bend is the error rule: everything else here
 * is about which reports fail a build, and that one is about a build being allowed to ship a document
 * Docuccino has already said is wrong.
 */
function acceptedCodes(string ...$codes): AcceptedCodes
{
    return AcceptedCodes::of($codes);
}

function acceptedDiagnostic(Severity $severity, string $code = 'eloquent.no-columns'): Diagnostic
{
    return new Diagnostic(severity: $severity, code: $code, message: 'm');
}

it('accepts a listed code at every severity but error', function (Severity $severity, bool $expected): void {
    expect(acceptedCodes('eloquent.no-columns')->accepts(acceptedDiagnostic($severity)))->toBe($expected);
})->with([
    'error is never accepted' => [Severity::Error, false],
    'warning' => [Severity::Warning, true],
    'info' => [Severity::Info, true],
    'hint' => [Severity::Hint, true],
]);

it('accepts nothing it does not name', function (Severity $severity): void {
    expect(acceptedCodes('other.code')->accepts(acceptedDiagnostic($severity)))->toBeFalse()
        ->and(AcceptedCodes::none()->accepts(acceptedDiagnostic($severity)))->toBeFalse();
})->with(Severity::cases());

it('reads a configured list as a sorted set, dropping what names no code', function (): void {
    $accepted = AcceptedCodes::of(['  b.two  ', 'a.one', 'b.two', '', '   ', 42, null, ['a.list']]);

    expect($accepted->codes)->toBe(['a.one', 'b.two']);
});

it('holds nothing when nothing is configured', function (): void {
    expect(AcceptedCodes::none()->codes)->toBe([])
        ->and(AcceptedCodes::of([])->codes)->toBe([]);
});

/*
 * The gate itself, over the whole `--fail-on` ladder. An accepted info is invisible to every floor;
 * an unaccepted one is caught by exactly the two floors quiet enough to see it.
 */
it('drops an accepted diagnostic from every floor', function (Severity $floor): void {
    expect(acceptedCodes('eloquent.no-columns')->fails([acceptedDiagnostic(Severity::Info)], $floor))->toBeFalse();
})->with(Severity::cases());

it('still fails on the same diagnostic unaccepted', function (Severity $floor, bool $expected): void {
    expect(AcceptedCodes::none()->fails([acceptedDiagnostic(Severity::Info)], $floor))->toBe($expected);
})->with([
    'hint floor catches an info' => [Severity::Hint, true],
    'info floor catches an info' => [Severity::Info, true],
    'warning floor does not' => [Severity::Warning, false],
    'error floor does not' => [Severity::Error, false],
]);

it('fails on an accepted code reported as an error, at every floor that can see one', function (Severity $floor, bool $expected): void {
    expect(acceptedCodes('eloquent.no-columns')->fails([acceptedDiagnostic(Severity::Error)], $floor))->toBe($expected);
})->with([
    'hint' => [Severity::Hint, true],
    'info' => [Severity::Info, true],
    'warning' => [Severity::Warning, true],
    'error' => [Severity::Error, true],
]);

it('fails on the unaccepted diagnostic beside an accepted one', function (): void {
    $diagnostics = [acceptedDiagnostic(Severity::Info), acceptedDiagnostic(Severity::Info, 'other.code')];

    expect(acceptedCodes('eloquent.no-columns')->fails($diagnostics, Severity::Info))->toBeTrue()
        ->and(acceptedCodes('eloquent.no-columns', 'other.code')->fails($diagnostics, Severity::Info))->toBeFalse();
});

it('fails on nothing when nothing was reported', function (Severity $floor): void {
    expect(acceptedCodes('eloquent.no-columns')->fails([], $floor))->toBeFalse();
})->with(Severity::cases());

it('names the accepted codes something reported as an error, and only those', function (): void {
    $accepted = acceptedCodes('a.one', 'b.two', 'c.three');
    $diagnostics = [
        acceptedDiagnostic(Severity::Error, 'b.two'),
        acceptedDiagnostic(Severity::Error, 'a.one'),
        acceptedDiagnostic(Severity::Error, 'a.one'),
        acceptedDiagnostic(Severity::Warning, 'c.three'),
        acceptedDiagnostic(Severity::Error, 'd.four'),
    ];

    // Sorted and deduped: this drives a diagnostic per code, and the console has to read the same
    // whichever route reported first.
    expect($accepted->refused($diagnostics))->toBe(['a.one', 'b.two']);
});

it('names no refusal where nothing errored', function (): void {
    expect(acceptedCodes('a.one')->refused([acceptedDiagnostic(Severity::Warning, 'a.one')]))->toBe([])
        ->and(acceptedCodes('a.one')->refused([]))->toBe([]);
});

it('names the entries nothing reported, in the order they are held', function (): void {
    $accepted = acceptedCodes('b.two', 'a.one', 'c.three');

    expect($accepted->unused(['b.two']))->toBe(['a.one', 'c.three'])
        ->and($accepted->unused(['a.one', 'b.two', 'c.three']))->toBe([])
        ->and($accepted->unused([]))->toBe(['a.one', 'b.two', 'c.three'])
        ->and(AcceptedCodes::none()->unused([]))->toBe([]);
});

it('counts what it accepted, by code', function (): void {
    $tally = acceptedCodes('b.two', 'a.one')->tally([
        acceptedDiagnostic(Severity::Info, 'b.two'),
        acceptedDiagnostic(Severity::Warning, 'a.one'),
        acceptedDiagnostic(Severity::Info, 'b.two'),
        // Neither of these was accepted: one is an error, one is not listed.
        acceptedDiagnostic(Severity::Error, 'a.one'),
        acceptedDiagnostic(Severity::Info, 'c.three'),
    ]);

    expect($tally)->toBe(['a.one' => 1, 'b.two' => 2]);
});

it('counts nothing where nothing was accepted', function (): void {
    expect(acceptedCodes('a.one')->tally([acceptedDiagnostic(Severity::Info, 'c.three')]))->toBe([])
        ->and(AcceptedCodes::none()->tally([acceptedDiagnostic(Severity::Info)]))->toBe([]);
});
