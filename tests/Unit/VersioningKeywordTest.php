<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Policy\VersioningPolicies;
use Docuccino\Core\Versioning\VersionOrder;

/*
 * One keyword, two tables. `documents.*.versioning` is read by `VersioningPolicies::for()`, which
 * decides whether a version bump is adequate, and by `VersionOrder::for()`, which decides which of two
 * versions is older and so which direction a change list is applied in. They must never disagree: a
 * keyword the policy honoured and the order did not would gate a release on a grammar the published
 * documents were never derived in.
 *
 * The rule is stated HERE rather than asked of either table, because a guard that asks the code for its
 * own rule agrees with whatever the code does. Both must answer to this, and only this.
 */

/**
 * What each keyword means, independently written down. `null` is "orders nothing".
 *
 * @return array<string, array{0: string, 1: string|null}>
 */
function versioningKeywordRule(): array
{
    return [
        // keyword => [the policy's name, the order's name]
        'date' => ['date', 'date'],
        'semver' => ['semver', 'semver'],
        'none' => ['none', null],
    ];
}

/**
 * The `match` arms one file's keyword table spells, as literal strings. Read from the source so a
 * keyword added to ONE table is a failure rather than a silence — a fixed dataset only proves the rows
 * it lists.
 *
 * @return list<string>
 */
function versioningKeywordArms(string $relative): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/'.$relative);

    preg_match('/match \(\$keyword\) \{(.*?)\n {8}};/s', $source, $body);

    preg_match_all("/'([^']*)' =>/", $body[1] ?? '', $arms);

    $found = $arms[1];
    sort($found);

    return $found;
}

it('answers one keyword the same way in both tables', function (string $keyword, string $policy, ?string $order): void {
    expect(VersioningPolicies::for($keyword)->name())->toBe($policy)
        ->and(VersionOrder::for($keyword)?->name())->toBe($order);
})->with(array_map(
    static fn (string $keyword, array $means): array => [$keyword, $means[0], $means[1]],
    array_keys(versioningKeywordRule()),
    array_values(versioningKeywordRule()),
));

it('takes an unrecognised keyword for `none` in both tables, rather than guessing in one', function (string $keyword): void {
    // Failing closed is the whole point: a typo must gate nothing AND order nothing, not order by
    // whichever grammar one of the two happened to guess.
    expect(VersioningPolicies::for($keyword)->name())->toBe('none')
        ->and(VersionOrder::for($keyword))->toBeNull();
})->with(['', 'SEMVER', 'Date', 'calver', 'semver ', 'dates']);

/*
 * The half a fixed dataset cannot see: a keyword added to one table and not the other, or to both and
 * not to the rule above. Read off the two `match` expressions themselves.
 */
it('spells the same keywords in both tables, and no more than the rule names', function (): void {
    $policies = versioningKeywordArms('php/core/src/Diff/Policy/VersioningPolicies.php');
    $orders = versioningKeywordArms('php/core/src/Versioning/VersionOrder.php');

    $named = array_keys(versioningKeywordRule());
    sort($named);

    // `none` is the default arm in both — spelled by neither `match`, and the rule says so.
    expect($policies)->toBe($orders)
        ->and($policies)->toBe(['date', 'semver'])
        ->and(array_diff($policies, $named))->toBe([])
        // A scan that matched nothing would pass forever.
        ->and(count($policies))->toBeGreaterThanOrEqual(2);
});
