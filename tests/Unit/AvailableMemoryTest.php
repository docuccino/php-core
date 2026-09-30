<?php

declare(strict_types=1);

use Docuccino\Core\Support\AvailableMemory;

/*
 * What a container's memory limit is, read the way the kernel writes it: cgroup v2 first, then v1. A limit
 * that is not set, and one that cannot be read, are both no limit.
 */

it('reads the limit a cgroup sets, and no limit where it sets none', function (array $files, ?int $expected): void {
    $memory = new AvailableMemory(static fn (string $path): ?string => $files[$path] ?? null);

    expect($memory->limit())->toBe($expected);
})->with([
    'cgroup v2' => [['/sys/fs/cgroup/memory.max' => "2147483648\n"], 2147483648],
    'cgroup v2, no limit' => [['/sys/fs/cgroup/memory.max' => "max\n"], null],
    // A v2 system has no v1 file, and a v1 file beside a v2 one is not the limit in force.
    'cgroup v2 before v1' => [['/sys/fs/cgroup/memory.max' => "max\n", '/sys/fs/cgroup/memory/memory.limit_in_bytes' => "1073741824\n"], null],
    'cgroup v1' => [['/sys/fs/cgroup/memory/memory.limit_in_bytes' => "1073741824\n"], 1073741824],
    // v1 writes "no limit" as the largest page-aligned count, which is wider than any limit anybody sets.
    'cgroup v1, no limit' => [['/sys/fs/cgroup/memory/memory.limit_in_bytes' => "9223372036854771712\n"], null],
    'not a container' => [[], null],
    'something else written there' => [['/sys/fs/cgroup/memory.max' => "2G\n"], null],
]);

it('reads this machine without a word, container or not', function (): void {
    // Where the files are absent, unreadable or outside open_basedir, the read says so by answering null,
    // never by a warning a host would turn into an exception.
    set_error_handler(static function (int $level, string $message): bool {
        if ((error_reporting() & $level) !== 0) {
            throw new ErrorException($message, 0, $level);
        }

        return false;
    });

    try {
        $limit = AvailableMemory::here()->limit();
    } finally {
        restore_error_handler();
    }

    expect($limit === null || $limit > 0)->toBeTrue();
});
