<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Support\Json;

/**
 * The invariant behind the fix, stated as a rule rather than as the one spelling that broke:
 *
 *   **A reading the YAML parser was free to choose must not change anything the build can observe.**
 *
 * Not the resolved values, not the fingerprint those values hash to, not the answer a typed read
 * gives, and not the diagnostics reported. `configHash` is a fragment-cache key input and a published
 * byte, so a reading that varies with which patch release of `symfony/yaml` a machine's lockfile
 * resolved is a determinism break that arrives from a dependency and looks exactly like a flake.
 *
 * Measured across the range `php/core` allows, one patch apart, the parser is free in two ways: it may
 * hand back an integer or that integer's float twin, and it may hand back any non-finite float for a
 * non-finite spelling. This file writes BOTH readings out and holds the reader to answering the same
 * thing for each — which states the rule on whichever single version happens to be installed, rather
 * than needing two of them side by side.
 *
 * That is the only shape this guard can honestly take. Comparing installed versions would pass on any
 * machine with one version, and asserting the type of a spelling is precisely what put a lockfile's
 * patch level into a test in the first place.
 */
it('resolves an integer and its float twin to one value, whichever the parser handed back', function (string $label, string $integer, string $twin): void {
    $a = ConfigFile::parse($integer);
    $b = ConfigFile::parse($twin);

    expect($a->values)->toBe($b->values)
        // The two readings must also be indistinguishable to whatever the value reaches next.
        ->and(Json::stable($a->values))->toBe(Json::stable($b->values))
        ->and($a->diagnostics)->toBe($b->diagnostics)
        ->and($a->diagnostics)->toBe([]);
})->with([
    // Each pair is one spelling's two readings: the left is what 7.4.15 gives for the spelling in the
    // label, the right is what 7.4.12 gives for it.
    '+1' => ['+1', "k: 1\n", "k: 1.0\n"],
    '+0' => ['+0', "k: 0\n", "k: 0.0\n"],
    '+1_000' => ['+1_000', "k: 1000\n", "k: 1000.0\n"],
    'nested' => ['+1 nested', "a:\n  b:\n    c: 7\n", "a:\n  b:\n    c: 7.0\n"],
    'in a list' => ['+1 in a list', "a:\n  - 1\n  - 2\n", "a:\n  - 1.0\n  - 2.0\n"],
    'a signed zero' => ['-0.0', "k: 0\n", "k: -0.0\n"],
]);

it('resolves every non-finite spelling to one value, whichever the parser handed back', function (): void {
    // The half a type-shaped guard would miss: these are all floats on every version in the range, and
    // WHICH float differs — `.nan` reads as NAN on one patch release and as INF on another. So the
    // reader may not carry any of them, and may not describe them apart either.
    $readings = array_map(
        static fn (string $spelling): ConfigFile => ConfigFile::parse('k: '.$spelling."\n"),
        ['.nan', '.NaN', '.inf', '-.inf', '.Inf'],
    );

    $first = $readings[0];

    expect($first->values)->toBe(['k' => null])
        ->and($first->diagnostics)->toHaveCount(1)
        ->and($first->diagnostics[0]->code)->toBe('config.value-not-finite');

    foreach ($readings as $reading) {
        expect($reading->values)->toBe($first->values)
            ->and(Json::stable($reading->values))->toBe(Json::stable($first->values))
            // Same code, same severity AND same words: a message that said "not a number" on one
            // patch release and "infinite" on another would leak the same fact one layer out.
            ->and(array_map(static fn (object $d): string => $d->message, $reading->diagnostics))
            ->toBe(array_map(static fn (object $d): string => $d->message, $first->diagnostics));
    }
});

it('lets no non-finite value out of the reader, at any depth', function (): void {
    // The structural half of the same rule. A non-finite float is also a value the canonical writer
    // refuses outright, so one reaching a document would be an exception at emit time with no file and
    // no setting named in it.
    $read = ConfigFile::parse(
        "top: .nan\nsection:\n  deep: .inf\n  list:\n    - -.inf\n    - 1.0\n    - ok\n",
    );

    $nonFinite = [];
    $walk = static function (mixed $node, string $path) use (&$walk, &$nonFinite): void {
        if (is_array($node)) {
            foreach ($node as $key => $member) {
                $walk($member, $path === '' ? (string) $key : $path.'.'.$key);
            }

            return;
        }

        if (is_float($node) && ! is_finite($node)) {
            $nonFinite[] = $path;
        }
    };

    $walk($read->values, '');

    expect($nonFinite)->toBe([])
        // Three settings refused, and one integral float settled to an int on the way past.
        ->and($read->diagnostics)->toHaveCount(3)
        ->and($read->values)->toBe([
            'top' => null,
            'section' => ['deep' => null, 'list' => [null, 1, 'ok']],
        ])
        // Executed, not asserted: the writer that would have thrown now takes the whole thing.
        ->and((new CanonicalJsonSerializer)->rejects($read->values))->toBeNull();
});

it('lets no float that is really an integer out of the reader, at any depth', function (): void {
    // The invariant stated directly rather than inferred from how some encoder renders it. A float
    // that is exactly an integer is the ONE value whose type the parser picks freely, so if none can
    // survive the reader then no observable can depend on which it picked.
    //
    // Over the representative fixture as well as a built input, because the fixture is what the golden
    // and the type table read: those two would go quiet on this axis if it ever regressed to a float
    // that renders the same either way.
    $sources = [
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/config/representative.yaml'),
        "a: 1.0\nb:\n  c: -0.0\n  d:\n    - 2.0\n    - 3.5\n    - 1e30\ne: 1.10\n",
    ];

    // 2^53, written out rather than read off the class, and derived from its own reason: a float only
    // names ONE integer up to here, so only up to here can the parser have been choosing between an
    // integer and its twin. Past it there is no twin to have chosen, so a big integral float is not
    // an offender — `1e30` below is in the input to hold that boundary in place. Deliberately not
    // PHP_INT_MAX, whose float cast rounds UP past itself.
    $unambiguous = 9007199254740992.0;

    $offenders = [];
    $walk = static function (mixed $node, string $path) use (&$walk, &$offenders, $unambiguous): void {
        if (is_array($node)) {
            foreach ($node as $key => $member) {
                $walk($member, $path === '' ? (string) $key : $path.'.'.$key);
            }

            return;
        }

        if (is_float($node) && is_finite($node) && $node === floor($node) && abs($node) <= $unambiguous) {
            $offenders[] = $path.' = '.var_export($node, true);
        }
    };

    $floats = 0;
    foreach ($sources as $source) {
        $read = ConfigFile::parse($source);
        $walk($read->values, '');

        // A scan that stopped seeing floats at all would pass forever, so count the ones that are
        // allowed to be there: the non-integral ones the reader must NOT touch.
        $values = $read->values;
        array_walk_recursive($values, static function (mixed $leaf) use (&$floats): void {
            $floats += is_float($leaf) ? 1 : 0;
        });
    }

    expect($offenders)->toBe([])
        ->and($floats)->toBeGreaterThanOrEqual(3);
});

it('agrees with the two layers downstream about what one number is', function (int $integer, float $twin): void {
    // Three sites decide whether an integer and its float twin are one value: this reader, the stable
    // fingerprint, and the canonical writer. The rule is written out here rather than read off any of
    // them, because a guard that asks one site for its rule agrees with whatever that site does — and
    // the reader disagreeing with the other two is exactly the defect this fixes.
    expect(Json::stable($integer))->toBe(Json::stable($twin))
        ->and((new CanonicalJsonSerializer)->serialize($integer))->toBe((new CanonicalJsonSerializer)->serialize($twin))
        ->and(ConfigFile::parse('k: '.$integer."\n")->values)
        ->toBe(ConfigFile::parse('k: '.number_format($twin, 1, '.', '')."\n")->values);
})->with([
    'one' => [1, 1.0],
    'zero' => [0, 0.0],
    'a thousand' => [1000, 1000.0],
    'negative' => [-7, -7.0],
]);

it('leaves a number the parser was not free about exactly as written', function (string $yaml, mixed $expected): void {
    // The settling is not a licence to tidy numbers generally. A non-integral float, a number past the
    // point where a float names one integer, and text that merely looks numeric are all readings the
    // parser makes the same way across the range — so none of them is ours to change.
    expect(ConfigFile::parse($yaml)->values)->toBe(['k' => $expected])
        ->and(ConfigFile::parse($yaml)->diagnostics)->toBe([]);
})->with([
    'a non-integral float' => ["k: 1.10\n", 1.1],
    'a float past exact integers' => ["k: 1e30\n", 1.0e30],
    'a version with three segments' => ["k: 0.14.0\n", '0.14.0'],
    'a quoted number' => ["k: '1.0'\n", '1.0'],
    'a file mode' => ["k: 0777\n", '0777'],
    'a date' => ["k: 2024-01-15\n", 1705276800],
    'a hex integer' => ["k: 0x1A\n", 26],
]);
