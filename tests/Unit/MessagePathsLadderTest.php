<?php

declare(strict_types=1);

use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\PathClaim;
use Docuccino\Core\Provenance\PathObjection;
use Docuccino\Core\Provenance\PathReason;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;

/**
 * The ladder itself, rather than the answers it gives. `MessagePathsTest` holds a row per shape, and
 * every guard beside it reads a TABLE — the wrapper list, the route methods. What no table can see
 * is the COMPOSITION: one reason trusted to be sufficient on its own where the true answer needed
 * two things to agree, which is where this class's defects live. So these rows enumerate every
 * reason and every objection with the claim it makes, and then drive the real class through each
 * way the two can meet.
 *
 * The composition rule is restated here in the test's own words and never read off the class: a
 * guard that asks the code for its own rule agrees with whatever the code does. Two deliberately
 * wrong rules are tried against the same corpus, because a corpus no wrong rule fails is not
 * discriminating.
 */
it('states what every reason proves, and how far', function (): void {
    // The table restated, not read. A case added without a row fails here in both directions, which
    // is the rule the wrapper table already obeys applied one level up.
    $rows = [
        // A wrapper, a drive and a UNC share are shapes nothing but a filesystem path has, from the
        // first character — no route signature, template or JSON pointer opens that way.
        'LocalWrapper' => [PathClaim::RunIsAPath, true],
        'WindowsRoot' => [PathClaim::RunIsAPath, true],
        // A root says nothing about the run; it says the text in FRONT of it is a directory this
        // machine was configured from or named for itself, which is a smaller claim and a surer one.
        // Both kinds are the same claim at the same strength: a home directory is no less this
        // machine's than the base path is, and only one of them was ever weighed.
        'RecognisedRoot' => [PathClaim::PrefixIsAMachineWord, true],
        'MachineRoot' => [PathClaim::PrefixIsAMachineWord, true],
        // Shape cannot tell `/api/users/{user}.json` from a file, so it may only speak unopposed.
        'FileShape' => [PathClaim::RunIsAPath, false],
    ];

    expect(array_map(static fn (PathReason $reason): string => $reason->name, PathReason::cases()))
        ->toEqualCanonicalizing(array_keys($rows));

    foreach (PathReason::cases() as $reason) {
        $row = $rows[$reason->name];

        expect($reason->proves())->toBe($row[0])
            ->and($reason->isConclusive())->toBe($row[1]);
    }

    // Anti-vacuity: a table holding one claim, or one strength, satisfies every row above and states
    // nothing about the distinction the whole composition turns on.
    expect(array_unique(array_map(static fn (PathReason $r): string => $r->proves()->name, PathReason::cases())))
        ->toHaveCount(2)
        ->and(array_unique(array_map(static fn (PathReason $r): bool => $r->isConclusive(), PathReason::cases())))
        ->toHaveCount(2);
});

it('states what every objection denies, how far, and where the text it objects to begins', function (): void {
    $rows = [
        // A wrapper's tail that is itself a URL names a host. Nothing answers that.
        'NestedScheme' => [PathClaim::RunIsAPath, true, ''],
        // A one-segment root is a container's checkout and equally a prefix an application mounts
        // routes under, so it is no evidence at all — which is why it denies rather than weakens.
        'ShallowRoot' => [PathClaim::PrefixIsAMachineWord, true, ''],
        // Strong evidence of a template and of an escape; weak evidence against a path, because a
        // shell glob and a Windows separator are spelled with them too.
        'Brace' => [PathClaim::RunIsAPath, false, '{}'],
        'Backslash' => [PathClaim::RunIsAPath, false, '\\'],
    ];

    expect(array_map(static fn (PathObjection $objection): string => $objection->name, PathObjection::cases()))
        ->toEqualCanonicalizing(array_keys($rows));

    foreach (PathObjection::cases() as $objection) {
        $row = $rows[$objection->name];

        expect($objection->opposes())->toBe($row[0])
            ->and($objection->isConclusive())->toBe($row[1])
            ->and($objection->characters())->toBe($row[2]);
    }

    // Both halves of both distinctions are present, and a conclusive objection names no characters
    // because nothing answers one — there is no anchor to measure in front of it.
    expect(array_unique(array_map(static fn (PathObjection $o): string => $o->opposes()->name, PathObjection::cases())))
        ->toHaveCount(2)
        ->and(array_unique(array_map(static fn (PathObjection $o): bool => $o->isConclusive(), PathObjection::cases())))
        ->toHaveCount(2);

    foreach (PathObjection::cases() as $objection) {
        expect($objection->characters() === '')->toBe($objection->isConclusive());
    }
});

it('rewrites exactly where a claim that stands covers the text it would remove', function (): void {
    // The composition rule, said HERE rather than asked of the class:
    //
    //   - nothing answers a conclusive objection, so nothing is rewritten;
    //   - a suggestive objection is answered only by a conclusive claim covering the text removed;
    //   - with nothing objecting, a claim of any strength is enough;
    //   - with no claim at all, nothing is rewritten.
    //
    // `support` below is the strongest claim that stands AND covers the text a rewrite would remove:
    // `RunIsAPath` covers the run, `PrefixIsAMachineWord` covers only the prefix a strip takes.
    $authorised = static fn (string $support, string $objection): bool => match ($objection) {
        'conclusive' => false,
        'suggestive' => $support === 'conclusive',
        default => $support !== 'none',
    };

    $rows = [
        // support = conclusive, nothing objecting.
        ['a wrapper with nothing objecting', 'conclusive', 'none', '/app/root',
            'Could not open file:///app/root/app/X.php', 'Could not open file://app/X.php'],
        ['a directory a recognised root accounts for', 'conclusive', 'none', '/app/root',
            'mkdir(/app/root/storage): Permission denied', 'mkdir(storage): Permission denied'],
        // The same cell reached by the other root. A directory names no file, so shape says nothing
        // and this is the only support there is — which is why it was published whole.
        ['a directory a machine root accounts for', 'conclusive', 'none', '/app/root',
            'scandir(/machine/home/Library/Caches) failed', 'scandir(Library/Caches) failed'],

        // support = conclusive against a suggestive objection: the four ways a brace or a backslash
        // is answered, which is the half that used to take a special case per way past.
        ['a glob whose braces are a shell pattern', 'conclusive', 'suggestive', '/app/root',
            'Could not open glob:///app/root/app/{Support,Http}/*.php', 'Could not open glob://app/{Support,Http}/*.php'],
        ['a drive whose braces are a shell pattern', 'conclusive', 'suggestive', 'C:\\Users\\bob\\dev\\checkout',
            'Could not open C:\\Users\\bob\\dev\\checkout\\app\\{a,b}\\X.php', 'Could not open app/{a,b}\\X.php'],
        ['a root in front of the brace', 'conclusive', 'suggestive', '/app/root',
            'Could not open /app/root/app/{Support,Http}/*.php', 'Could not open app/{Support,Http}/*.php'],
        ['a root in front of a class name later in the sentence', 'conclusive', 'suggestive', '/app/root',
            'Failed in /app/root/app/X.php on line 3 for App\\Foo', 'Failed in app/X.php on line 3 for App\\Foo'],
        ['a machine root in front of a brace', 'conclusive', 'suggestive', '/app/root',
            'Could not open /machine/home/secret/{a,b}/x.php', 'Could not open secret/{a,b}/x.php'],
        // The application's own root, with a class name after it. The claim covers the path run and
        // the path run is all a rewrite removes, so demanding it cover the sentence too published the
        // root whole while the same message without the class reduced.
        ['a root that IS the run, with a class after it', 'conclusive', 'suggestive', '/app/root',
            'Analysed files in /app/root for App\\Http\\Kernel', 'Analysed files in  for App\\Http\\Kernel'],

        // support = suggestive, nothing objecting. The second row is the whole of what a shallow root
        // still costs nothing: it loses its own claim and the run carries on to shape.
        ['shape alone, outside every root', 'suggestive', 'none', '/app/root',
            'file_get_contents(/elsewhere/cache/acme/Reader.php): Failed to open stream',
            'file_get_contents(Reader.php): Failed to open stream'],
        ['a file under a one-segment root', 'suggestive', 'none', '/app',
            'Could not open /app/src/Foo.php', 'Could not open src/Foo.php'],

        // support = suggestive against a suggestive objection: neither settles it, so the run stands.
        // Both of these are leaks in the second case and over-scrubs in the first if the rule slips,
        // and the second direction is the one that must be impossible.
        ['shape against a brace', 'suggestive', 'suggestive', '/app/root',
            'Unknown route /api/users/{user}/avatar.png', 'Unknown route /api/users/{user}/avatar.png'],
        ['shape against a backslash', 'suggestive', 'suggestive', '/Users/ca rol/checkout',
            'Refused /Users/ca rol/secret/x\\d+/y.php', 'Refused /Users/ca rol/secret/x\\d+/y.php'],

        // support = suggestive against a conclusive objection.
        ['shape behind a wrapper naming a host', 'suggestive', 'conclusive', '/app/root',
            'Could not open compress.zlib://http://example.com/archive.gz',
            'Could not open compress.zlib://http://example.com/archive.gz'],

        // support = none. The second row is a route the checkout root is a prefix of, where the only
        // reason that stood was denied outright rather than weakened.
        ['a documentation link nothing proves is a path', 'none', 'none', '/app/root',
            'See /docs/reference/configuration for the key.', 'See /docs/reference/configuration for the key.'],
        ['a route under a one-segment root', 'none', 'none', '/app',
            'Unknown route /app/users/profile', 'Unknown route /app/users/profile'],
        ['a template naming no file', 'none', 'suggestive', '/app/root',
            'Unknown route /api/users/{user}', 'Unknown route /api/users/{user}'],
        ['a wrapper naming a host and no file', 'none', 'conclusive', '/app/root',
            'Could not open compress.zlib://http://example.com/downloads',
            'Could not open compress.zlib://http://example.com/downloads'],
    ];

    $published = [];
    $expected = [];
    $rewrote = [];
    $predicted = [];
    $cells = [];

    // The home is an INPUT, not the host's: a row asserting what `getenv('HOME')` happens to be
    // asserts a different thing on every machine, and the shallow case (`/root`) would assert the
    // opposite of the deep one. Every other row's paths sit outside it, so it changes nothing else.
    $restore = getenv('HOME');

    try {
        putenv('HOME=/machine/home');

        foreach ($rows as [$case, $support, $objection, $base, $message, $answer]) {
            $out = (new MessagePaths(new RootRelativeSourcePathResolver($base)))->relative($message);

            $published[$case] = $out;
            $expected[$case] = $answer;
            $rewrote[$case] = $out !== $message;
            $predicted[$case] = $authorised($support, $objection);
            $cells[$support.'/'.$objection] = true;
        }
    } finally {
        putenv($restore === false ? 'HOME' : 'HOME='.$restore);
    }

    expect($published)->toBe($expected)
        ->and($rewrote)->toBe($predicted);

    // Every cell of the table but one, asserted as a union rather than left to the rows to imply. The
    // missing cell is conclusive support against a conclusive objection, and it is empty by
    // construction — see the row below this test.
    expect(array_keys($cells))->toEqualCanonicalizing([
        'conclusive/none', 'conclusive/suggestive',
        'suggestive/none', 'suggestive/suggestive', 'suggestive/conclusive',
        'none/none', 'none/suggestive', 'none/conclusive',
    ]);

    // Discrimination. A corpus no wrong rule fails proves nothing about the right one, so each of
    // these has to disagree with the class on at least one row. The first is the exclusions removed
    // rather than answered; the second is the veto they used to be; the third keeps both directions
    // and drops the strength, which is the distinction all four defects turned on.
    $wrong = [
        'a reason is enough, whatever objects' => static fn (string $s, string $o): bool => $s !== 'none',
        'an objection vetoes, whatever stands' => static fn (string $s, string $o): bool => $o === 'none' && $s !== 'none',
        'strength does not matter' => static fn (string $s, string $o): bool => $s !== 'none' && $o !== 'conclusive',
    ];

    foreach ($wrong as $rule) {
        $guesses = [];

        foreach ($rows as [$case, $support, $objection]) {
            $guesses[$case] = $rule($support, $objection);
        }

        expect($guesses)->not->toBe($predicted);
    }
});

it('cannot put a conclusive reason and a conclusive objection on one run', function (): void {
    // The one cell of the table above with no row, asserted rather than left as a gap. A run trips
    // `NestedScheme` only where it OPENS with a proof scheme whose tail is another URL, and that is
    // exactly when `LocalWrapper` stops standing; a drive and a UNC share carry no scheme to nest
    // under. So the cell is empty by construction, and this is what fails if that stops being true.
    $paths = new MessagePaths(new RootRelativeSourcePathResolver('/app/root'));
    $stands = new ReflectionMethod(MessagePaths::class, 'stands');
    $trips = new ReflectionMethod(MessagePaths::class, 'trips');

    $runs = [
        'phar:///app/root/vendor/acme/acme.phar/src/X.php',
        'compress.zlib://http://example.com/archive.gz',
        'compress.zlib://compress.bzip2:///home/alice/x.bz2',
        'zip://s3v4://bucket/x.zip',
        'file:///app/root/app/{a}/X.php',
        'C:\\Users\\bob\\dev\\checkout\\app\\X.php',
        '\\\\SERVER\\share\\app\\X.php',
        '/app/root/app/X.php',
        '/api/users/{user}',
    ];

    $sawReason = false;
    $sawObjection = false;

    foreach ($runs as $run) {
        $reason = false;
        $objection = false;

        foreach (PathReason::cases() as $case) {
            $reason = $reason || ($case->proves() === PathClaim::RunIsAPath
                && $case->isConclusive()
                && $stands->invoke($paths, $case, $run) === true);
        }

        foreach (PathObjection::cases() as $case) {
            $objection = $objection || ($case->opposes() === PathClaim::RunIsAPath
                && $case->isConclusive()
                && $trips->invoke($paths, $case, $run) === true);
        }

        expect($reason && $objection)->toBeFalse();

        $sawReason = $sawReason || $reason;
        $sawObjection = $sawObjection || $objection;
    }

    // Anti-vacuity: a run list where neither half ever fires agrees with any rule at all.
    expect($sawReason)->toBeTrue()
        ->and($sawObjection)->toBeTrue();
});

it('composes out of the two tables and nothing else', function (): void {
    // What the enum guards above cannot see. A fifth reason does not have to arrive as a case: added
    // as one more `if` in the middle of the rewrite it answers alone, exactly as the four defects
    // did, and every table guard still passes. So these six methods — the whole of where the ladder
    // composes — have their call sets written down, and a predicate that is not one of them fails
    // here until somebody says which reason or objection it is.
    //
    // Executed against a real reading of the source rather than a claim about it: the lists come off
    // reflection, so a method renamed out of existence fails too.
    $composition = [
        'rewrite' => ['admits', 'attributed', 'candidates', 'isAPath', 'pathRun', 'resolve', 'rtrim', 'scrub', 'strlen', 'substr'],
        'admits' => ['answered', 'objections'],
        'answered' => ['candidates', 'characters', 'conclusivelyAPath', 'isConclusive', 'machineWord', 'strcspn', 'substr'],
        'isAPath' => ['reasons'],
        'conclusivelyAPath' => ['array_filter', 'isConclusive', 'reasons'],
        'machineWord' => ['array_filter', 'isConclusive', 'objections', 'reasons'],
    ];

    // Language constructs a body is written WITH rather than predicates it decides by.
    $constructs = ['if', 'foreach', 'for', 'while', 'match', 'fn', 'function', 'return', 'switch', 'catch', 'array', 'isset', 'list', 'elseif', 'empty', 'unset'];

    $found = [];

    foreach (array_keys($composition) as $name) {
        $method = new ReflectionMethod(MessagePaths::class, $name);
        $file = $method->getFileName();
        expect($file)->toBeString();

        /** @var list<string> $lines */
        $lines = file((string) $file) ?: [];
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        // Comments name the helpers they point at, and a docblock's `{@see pathRun()}` is not a call.
        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*\(/', (string) preg_replace('#//.*$#m', '', $body), $hits);

        $calls = array_values(array_unique(array_diff($hits[1], $constructs, [$name])));
        sort($calls);
        $found[$name] = $calls;
    }

    expect($found)->toBe($composition);

    // Anti-vacuity: a regex that stopped matching leaves every list empty and agrees forever.
    expect(array_sum(array_map(count(...), $found)))->toBeGreaterThanOrEqual(20);
});

it('has a decision about every reason and every objection it enumerates', function (): void {
    // The guard executed rather than claimed. `stands()` and `trips()` are exhaustive matches over
    // these enums, as are `proves()`, `opposes()`, `isConclusive()` and `characters()` — so a case
    // added without a decision throws `UnhandledMatchError` here, and fails `composer analyse` before
    // it ever gets this far. A comment may not claim that; this is what executes it.
    $paths = new MessagePaths(new RootRelativeSourcePathResolver('/app/root'));
    $stands = new ReflectionMethod(MessagePaths::class, 'stands');
    $trips = new ReflectionMethod(MessagePaths::class, 'trips');

    foreach (PathReason::cases() as $reason) {
        expect($stands->invoke($paths, $reason, '/app/root/app/X.php'))->toBeBool()
            ->and($reason->proves())->toBeInstanceOf(PathClaim::class)
            ->and($reason->isConclusive())->toBeBool();
    }

    foreach (PathObjection::cases() as $objection) {
        expect($trips->invoke($paths, $objection, '/app/root/app/X.php'))->toBeBool()
            ->and($objection->opposes())->toBeInstanceOf(PathClaim::class)
            ->and($objection->isConclusive())->toBeBool()
            ->and($objection->characters())->toBeString();
    }

    // And both claims are actually contested: one that nothing proves, or nothing denies, is a member
    // of the vocabulary that no longer decides anything.
    foreach (PathClaim::cases() as $claim) {
        expect(array_filter(PathReason::cases(), static fn (PathReason $r): bool => $r->proves() === $claim))
            ->not->toBeEmpty()
            ->and(array_filter(PathObjection::cases(), static fn (PathObjection $o): bool => $o->opposes() === $claim))
            ->not->toBeEmpty();
    }
});
