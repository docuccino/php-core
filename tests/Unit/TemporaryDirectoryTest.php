<?php

declare(strict_types=1);

/*
 * The sweep suites run in afterEach, which runs after a skipped test as well — so a suite whose setup never
 * got to assign its directory hands the sweep '', and `'' . '/*'` is every top-level entry on the machine.
 * The sweep takes a directory this process made and nothing else, and never follows a link out of it.
 *
 * Every path refused here is one this test made for the purpose, holding a file that has to survive, so a
 * sweep that stopped refusing would take a sentinel with it and fail, rather than take the machine's.
 */

/** A directory at `$path` holding one file, whose path comes back. */
function sweepSentinel(string $path): string
{
    mkdir($path, 0700);
    file_put_contents($path.'/kept', '');

    return $path.'/kept';
}

it('takes away nothing it did not make', function (Closure $stage): void {
    $scratch = sys_get_temp_dir().'/sentinel-'.getmypid().'-'.bin2hex(random_bytes(6));
    [$refused, $kept, $made] = $stage($scratch);

    try {
        removeTemporaryDirectory($refused);

        expect(is_file($kept))->toBeTrue();
    } finally {
        foreach ([...$made, $scratch] as $path) {
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @unlink($path.'/kept');
                @rmdir($path);
            }
        }
    }
})->with([
    // The shape the sweep is handed by a suite that skipped before assigning its directory.
    'a path never assigned' => [static fn (string $scratch): array => ['', sweepSentinel($scratch), []]],
    'nothing at all' => [static fn (string $scratch): array => [null, sweepSentinel($scratch), []]],
    'a directory that is not docuccino\'s' => [static fn (string $scratch): array => [$scratch, sweepSentinel($scratch), []]],
    'another process\'s directory' => [static function (string $scratch): array {
        $foreign = sys_get_temp_dir().'/docuccino-sweep-'.(getmypid() + 1).'-'.bin2hex(random_bytes(6));

        return [$foreign, sweepSentinel($foreign), [$foreign]];
    }],
    'a directory inside one of ours' => [static function (string $scratch): array {
        $ours = temporaryDirectory('sweep');

        return [$ours.'/inner', sweepSentinel($ours.'/inner'), [$ours.'/inner', $ours]];
    }],
    'a link named like one of ours' => [static function (string $scratch): array {
        $link = sys_get_temp_dir().'/docuccino-sweep-'.getmypid().'-'.bin2hex(random_bytes(6));
        $kept = sweepSentinel($scratch);
        symlink($scratch, $link);

        return [$link, $kept, [$link]];
    }],
]);

it('takes away a directory of its own whole, without following a link out of it', function (): void {
    $outside = sys_get_temp_dir().'/sentinel-'.getmypid().'-'.bin2hex(random_bytes(6));
    $kept = sweepSentinel($outside);

    $directory = temporaryDirectory('sweep');
    mkdir($directory.'/nested');
    file_put_contents($directory.'/nested/.gitignore', '*');
    file_put_contents($directory.'/file', '');
    symlink($outside, $directory.'/link');

    try {
        removeTemporaryDirectory($directory);

        expect(file_exists($directory))->toBeFalse()
            ->and(is_file($kept))->toBeTrue();
    } finally {
        @unlink($kept);
        @rmdir($outside);
    }
});
