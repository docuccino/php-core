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

function acceptedDiagnostic(Severity $severity, string $code = 'eloquent.no-columns', ?string $route = null): Diagnostic
{
    return new Diagnostic(severity: $severity, code: $code, message: 'm', routeSignature: $route);
}

/** @return array{code: string, operation: string|null} */
function acceptanceEntry(string $code, ?string $operation = null): array
{
    return ['code' => $code, 'operation' => $operation];
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

    expect($accepted->entries)->toBe([acceptanceEntry('a.one'), acceptanceEntry('b.two')]);
});

it('reads an entry scoped to an operation beside the whole codes, a whole code first', function (): void {
    $accepted = AcceptedCodes::of([
        ['code' => 'b.two', 'operation' => 'POST /api/b'],
        ['code' => ' a.one ', 'operation' => ' GET /api/a '],
        'a.one',
        ['code' => 'b.two', 'operation' => 'POST /api/b'],
        // A map naming only a code says what a string does.
        ['code' => 'c.three'],
    ]);

    expect($accepted->entries)->toBe([
        acceptanceEntry('a.one'),
        acceptanceEntry('a.one', 'GET /api/a'),
        acceptanceEntry('b.two', 'POST /api/b'),
        acceptanceEntry('c.three'),
    ]);
});

/*
 * A scope that cannot be read must not fall back to the whole code: that would quiet every finding of
 * it, on every route, for an entry its author wrote to quiet one.
 */
it('drops an entry whose scope it cannot read rather than accepting its whole code', function (mixed $entry): void {
    expect(AcceptedCodes::of([$entry])->entries)->toBe([]);
})->with([
    'a misspelled scope' => [['code' => 'a.one', 'opration' => 'GET /api/a']],
    'a scope it does not have' => [['code' => 'a.one', 'pointer' => '/paths/~1api~1a']],
    'an empty operation' => [['code' => 'a.one', 'operation' => '  ']],
    'an operation that is not text' => [['code' => 'a.one', 'operation' => ['GET /api/a']]],
    'a null operation' => [['code' => 'a.one', 'operation' => null]],
    'no code' => [['operation' => 'GET /api/a']],
    'a code that is not text' => [['code' => 42, 'operation' => 'GET /api/a']],
]);

it('holds nothing when nothing is configured', function (): void {
    expect(AcceptedCodes::none()->entries)->toBe([])
        ->and(AcceptedCodes::of([])->entries)->toBe([]);
});

/*
 * The reason a scope exists: one code can be a reviewed, deliberate finding on one route and a defect
 * on the next, and accepting the first must leave the gate catching the second.
 */
it('accepts a code scoped to an operation there and nowhere else', function (): void {
    $accepted = AcceptedCodes::of([['code' => 'attribute.body-parameter-narrower', 'operation' => 'POST /api/answers']]);
    $reviewed = acceptedDiagnostic(Severity::Info, 'attribute.body-parameter-narrower', 'POST /api/answers');
    $elsewhere = acceptedDiagnostic(Severity::Info, 'attribute.body-parameter-narrower', 'POST /api/drafts');
    $routeless = acceptedDiagnostic(Severity::Info, 'attribute.body-parameter-narrower');

    expect($accepted->accepts($reviewed))->toBeTrue()
        ->and($accepted->accepts($elsewhere))->toBeFalse()
        ->and($accepted->accepts($routeless))->toBeFalse()
        ->and($accepted->fails([$reviewed], Severity::Hint))->toBeFalse()
        ->and($accepted->fails([$reviewed, $elsewhere], Severity::Hint))->toBeTrue()
        ->and($accepted->tally([$reviewed, $elsewhere]))->toBe(['attribute.body-parameter-narrower' => 1]);
});

it('matches an operation exactly as it is spelled', function (string $operation): void {
    $accepted = AcceptedCodes::of([['code' => 'a.one', 'operation' => $operation]]);

    expect($accepted->accepts(acceptedDiagnostic(Severity::Info, 'a.one', 'POST /api/answers')))->toBeFalse();
})->with(['post /api/answers', 'POST api/answers', 'POST /api/answers/', 'POST /api/*', 'POST']);

it('still accepts a whole code on every route', function (): void {
    $accepted = acceptedCodes('a.one');

    expect($accepted->accepts(acceptedDiagnostic(Severity::Info, 'a.one', 'GET /api/a')))->toBeTrue()
        ->and($accepted->accepts(acceptedDiagnostic(Severity::Info, 'a.one', 'POST /api/b')))->toBeTrue()
        ->and($accepted->accepts(acceptedDiagnostic(Severity::Info, 'a.one')))->toBeTrue();
});

it('never accepts an error at the operation it is scoped to', function (): void {
    $accepted = AcceptedCodes::of([['code' => 'a.one', 'operation' => 'GET /api/a']]);
    $error = acceptedDiagnostic(Severity::Error, 'a.one', 'GET /api/a');

    expect($accepted->accepts($error))->toBeFalse()
        ->and($accepted->fails([$error], Severity::Error))->toBeTrue()
        ->and($accepted->refused([$error]))->toBe([acceptanceEntry('a.one', 'GET /api/a')])
        ->and($accepted->refused([acceptedDiagnostic(Severity::Error, 'a.one', 'GET /api/b')]))->toBe([]);
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
    expect($accepted->refused($diagnostics))->toBe([acceptanceEntry('a.one'), acceptanceEntry('b.two')]);
});

it('names no refusal where nothing errored', function (): void {
    expect(acceptedCodes('a.one')->refused([acceptedDiagnostic(Severity::Warning, 'a.one')]))->toBe([])
        ->and(acceptedCodes('a.one')->refused([]))->toBe([]);
});

it('names the entries nothing reported, in the order they are held', function (): void {
    $accepted = acceptedCodes('b.two', 'a.one', 'c.three');
    $reported = static fn (string ...$codes): array => array_map(
        static fn (string $code): Diagnostic => acceptedDiagnostic(Severity::Info, $code),
        array_values($codes),
    );

    expect($accepted->unused($reported('b.two')))->toBe([acceptanceEntry('a.one'), acceptanceEntry('c.three')])
        ->and($accepted->unused($reported('a.one', 'b.two', 'c.three')))->toBe([])
        ->and($accepted->unused([]))->toBe([acceptanceEntry('a.one'), acceptanceEntry('b.two'), acceptanceEntry('c.three')])
        ->and(AcceptedCodes::none()->unused([]))->toBe([]);
});

/*
 * A scoped entry goes stale on its own: the code still firing somewhere else does not keep alive an
 * entry for a route that was renamed or fixed — which is what lets a list scoped by route stay true to
 * the application's shape instead of quietly drifting from it.
 */
it('names a scoped entry nothing reported at its operation, even while its code fires elsewhere', function (): void {
    $accepted = AcceptedCodes::of([
        'a.one',
        ['code' => 'b.two', 'operation' => 'GET /api/renamed'],
        ['code' => 'b.two', 'operation' => 'GET /api/kept'],
    ]);

    expect($accepted->unused([
        acceptedDiagnostic(Severity::Info, 'a.one', 'GET /api/anywhere'),
        acceptedDiagnostic(Severity::Info, 'b.two', 'GET /api/kept'),
        acceptedDiagnostic(Severity::Info, 'b.two', 'GET /api/new-name'),
    ]))->toBe([acceptanceEntry('b.two', 'GET /api/renamed')]);
});

it('counts an entry reported only as an error as refused, not unused', function (): void {
    $accepted = AcceptedCodes::of([['code' => 'a.one', 'operation' => 'GET /api/a']]);

    expect($accepted->unused([acceptedDiagnostic(Severity::Error, 'a.one', 'GET /api/a')]))->toBe([]);
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
