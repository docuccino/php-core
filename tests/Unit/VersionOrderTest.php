<?php

declare(strict_types=1);

use Docuccino\Core\Versioning\VersionOrder;

/**
 * The one reading of "which of these two versions is older".
 *
 * It exists because `strcmp` was that reading in two places and is wrong for both grammars the moment a
 * number grows a digit. Dates survive it by being fixed-width; semver does not, and a rule that is right
 * by luck breaks silently when the second grammar arrives.
 */
it('orders semver where a bytewise comparison gets it backwards', function (): void {
    $order = VersionOrder::semver();

    // The whole case: `1.10.0` is NEWER than `1.9.0`, and `strcmp` says the opposite because `1` sorts
    // before `9`. Both halves are asserted, so this fails if the reading ever goes back to bytes.
    expect($order->compare('1.9.0', '1.10.0'))->toBeLessThan(0)
        ->and(strcmp('1.9.0', '1.10.0'))->toBeGreaterThan(0)
        ->and($order->compare('1.10.0', '1.9.0'))->toBeGreaterThan(0)
        ->and($order->compare('2.0.0', '10.0.0'))->toBeLessThan(0)
        ->and(strcmp('2.0.0', '10.0.0'))->toBeGreaterThan(0);
});

it('orders semver by its three numbers, ignoring what comes after them', function (string $a, string $b, string $expected): void {
    $delta = VersionOrder::semver()->compare($a, $b);

    expect($delta <=> 0)->toBe(['older' => -1, 'same' => 0, 'newer' => 1][$expected]);
})->with([
    'a major apart' => ['1.0.0', '2.0.0', 'older'],
    'a minor apart' => ['1.2.0', '1.3.0', 'older'],
    'a patch apart' => ['1.2.3', '1.2.4', 'older'],
    'the same' => ['1.2.3', '1.2.3', 'same'],
    'newer' => ['3.0.0', '1.9.9', 'newer'],
    'a pre-release of the same release' => ['1.2.3', '1.2.3-rc1', 'same'],
    'build metadata' => ['1.2.3+build.9', '1.2.3', 'same'],
]);

it('orders dates by the date they begin with, ignoring what comes after it', function (string $a, string $b, string $expected): void {
    $delta = VersionOrder::date()->compare($a, $b);

    expect($delta <=> 0)->toBe(['older' => -1, 'same' => 0, 'newer' => 1][$expected]);
})->with([
    'a year apart' => ['2025-09-01', '2026-09-01', 'older'],
    'a month apart' => ['2026-09-01', '2026-10-01', 'older'],
    'a day apart' => ['2026-09-02', '2026-09-01', 'newer'],
    'the same' => ['2026-09-01', '2026-09-01', 'same'],
    'a suffix' => ['2026-09-01', '2026-09-01-rc1', 'same'],
]);

it('compares nothing it cannot read, rather than taking a silent zero', function (): void {
    expect(VersionOrder::semver()->compare('v2', '1.0.0'))->toBeNull()
        ->and(VersionOrder::semver()->compare('1.0.0', '1.0'))->toBeNull()
        ->and(VersionOrder::date()->compare('2026-09-01', 'autumn'))->toBeNull()
        ->and(VersionOrder::semver()->reads('1.0'))->toBeFalse()
        ->and(VersionOrder::date()->reads('2026-9-1'))->toBeFalse();
});

it('names the order the versioning keyword names, and orders nothing by a keyword that orders nothing', function (mixed $keyword, ?string $name): void {
    expect(VersionOrder::for($keyword)?->name())->toBe($name);
})->with([
    'date' => ['date', 'date'],
    'semver' => ['semver', 'semver'],
    'none' => ['none', null],
    'a typo' => ['sermver', null],
    'nothing' => ['', null],
]);

/*
 * The derived default: a document that names no keyword still gets an order, from the shape its own
 * versions are written in. That is what keeps `versioning` from being a knob everyone who turns on API
 * versioning has to remember to turn.
 */
it('reads the order off the versions themselves when nothing names one', function (array $versions, ?string $name): void {
    expect(VersionOrder::detect($versions)?->name())->toBe($name);
})->with([
    'all dates' => [['2026-09-01', '2026-06-01'], 'date'],
    'all semver' => [['1.9.0', '1.10.0'], 'semver'],
    'one date' => [['2026-09-01'], 'date'],
    // A mixture cannot be ordered by either grammar, and guessing which half to believe is how a change
    // list quietly applies in the wrong order.
    'a mixture' => [['2026-09-01', '1.0.0'], null],
    'neither' => [['v1', 'v2'], null],
    'nothing at all' => [[], null],
]);

/*
 * Dates are tried before semver, and it has to be that way round: `2026.09.01` is not semver, but a
 * date-shaped set must never be read as anything else, and the two grammars overlap nowhere else.
 */
it('prefers the date reading where a set could plausibly be either', function (): void {
    expect(VersionOrder::detect(['2026-09-01'])?->name())->toBe('date')
        ->and(VersionOrder::semverParts('2026-09-01'))->toBeNull()
        ->and(VersionOrder::date()->reads('1.0.0'))->toBeFalse();
});

/*
 * The one sorting of a version set. `strcmp` is the reading this class exists to replace, and a set
 * published in that order is the defect shipped in an artifact rather than in a change list.
 */
it('sorts a set oldest first under the order it is given', function (?string $keyword, array $versions, array $sorted): void {
    expect(VersionOrder::sorted($versions, $keyword === null ? null : VersionOrder::for($keyword)))->toBe($sorted);
})->with([
    'semver, stated' => ['semver', ['1.10.0', '1.9.0', '1.2.0'], ['1.2.0', '1.9.0', '1.10.0']],
    'semver, detected' => [null, ['1.10.0', '1.9.0', '1.2.0'], ['1.2.0', '1.9.0', '1.10.0']],
    'dates, stated' => ['date', ['2026-12-01', '2026-06-01'], ['2026-06-01', '2026-12-01']],
    'dates, detected' => [null, ['2026-12-01', '2026-06-01'], ['2026-06-01', '2026-12-01']],
    // Nothing reads the set whole, so byte order — deterministic, and the only thing left.
    'a mixture' => [null, ['1.10.0', '2026-06-01', '1.9.0'], ['1.10.0', '1.9.0', '2026-06-01']],
    // A stated order that cannot read every member is no order for this set either.
    'stated but unreadable' => ['date', ['1.10.0', '1.9.0'], ['1.10.0', '1.9.0']],
    'nothing to sort' => [null, [], []],
]);

it('breaks a tie bytewise, so the answer never depends on the order the set arrived in', function (): void {
    // As dates these two are the same day, so the comparison alone would leave them where they were.
    expect(VersionOrder::sorted(['2026-06-01-rc2', '2026-06-01-rc1'], VersionOrder::date()))
        ->toBe(['2026-06-01-rc1', '2026-06-01-rc2'])
        ->and(VersionOrder::sorted(['2026-06-01-rc1', '2026-06-01-rc2'], VersionOrder::date()))
        ->toBe(['2026-06-01-rc1', '2026-06-01-rc2']);
});
