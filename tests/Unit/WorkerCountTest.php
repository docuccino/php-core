<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\WorkerCount;
use Docuccino\Core\Support\AvailableCpus;
use Docuccino\Core\Support\AvailableMemory;

/*
 * The most workers a build may use: what the application configured, where it wrote a count, and otherwise
 * as many as this process's CPUs and its container's memory allow, up to eight. A count written but not
 * usable builds in one process: whoever wrote it may have been turning forking off, and an off switch that
 * fails open forks the very build it was written to stop.
 */

/** CPUs that answer `$count`, however the machine is asked. */
function cpusAnswering(?int $count): AvailableCpus
{
    return new AvailableCpus(
        static fn (string $path): ?string => $path === '/proc/self/status' && $count !== null ? "Cpus_allowed_list:\t0-".($count - 1)."\n" : null,
        static fn (string $command): ?string => null,
    );
}

/** A cgroup that allows `$bytes` of memory, or sets no limit where that is null. */
function memoryAnswering(?int $bytes): AvailableMemory
{
    return new AvailableMemory(static fn (string $path): ?string => $path === '/sys/fs/cgroup/memory.max' ? ($bytes === null ? "max\n" : $bytes."\n") : null);
}

it('uses a configured count as it stands, above the automatic cap too', function (int $configured): void {
    expect(WorkerCount::of($configured, cpusAnswering(4), memoryAnswering(null)))->toBe($configured);
})->with([1, 3, 16]);

it('builds in one process for a configured count below one', function (int $configured): void {
    // Zero workers is no workers. A negative count means nothing at all, and the reading that can do no harm
    // is the one that forks nothing.
    expect(WorkerCount::of($configured, cpusAnswering(4), memoryAnswering(null)))->toBe(1);
})->with([
    'zero' => [0],
    'a negative count' => [-2],
]);

it('builds in one process for a count written as anything but a whole number', function (mixed $configured): void {
    // Each of these is refused where the file is read (config.value-type), naming this same fallback.
    expect(WorkerCount::of($configured, cpusAnswering(4), memoryAnswering(null)))->toBe(1)
        ->and(WorkerCount::UNUSABLE)->toBe(1);
})->with([
    'off, as a boolean' => [false],
    'on, as a boolean' => [true],
    'a word' => ['auto'],
    'a numeral in quotes' => ['1'],
    'a larger numeral in quotes' => ['4'],
    'a fraction' => [2.5],
    'a list' => [[4]],
]);

it('works the count out where nothing is configured', function (): void {
    expect(WorkerCount::of(null, cpusAnswering(4), memoryAnswering(null)))->toBe(4);
});

it('caps the count it works out, and falls back to one where no CPU count can be read', function (): void {
    expect(WorkerCount::of(null, cpusAnswering(64), memoryAnswering(null)))->toBe(WorkerCount::MAX)
        ->and(WorkerCount::of(null, cpusAnswering(1), memoryAnswering(null)))->toBe(1)
        ->and(WorkerCount::of(null, cpusAnswering(null), memoryAnswering(null)))->toBe(1);
});

it('starts no more workers than fit beside the build in its memory limit, each at the ceiling it may use', function (?int $limit, ?int $ceiling, int $expected): void {
    // Every process — the build and each worker — may grow to the whole `memory_limit`, so a container whose
    // memory runs out before its CPUs do holds that many processes and no more: past it, the kernel kills one.
    expect(WorkerCount::of(null, cpusAnswering(16), memoryAnswering($limit), $ceiling))->toBe($expected);
})->with([
    'no limit set' => [null, 2 * 1024 ** 3, 8],
    'room for the build and three' => [8 * 1024 ** 3, 2 * 1024 ** 3, 3],
    // One worker while the build waits on it is the build's own work in a second process: nothing gained.
    'room for the build and one worker' => [4 * 1024 ** 3, 2 * 1024 ** 3, 1],
    'room for the build alone' => [3 * 1024 ** 3, 2 * 1024 ** 3, 1],
    'more room than CPUs' => [64 * 1024 ** 3, 512 * 1024 ** 2, 8],
    // A process with no ceiling of its own may take all of it, so nothing fits beside it.
    'an unlimited ceiling' => [8 * 1024 ** 3, PHP_INT_MAX, 1],
    'a ceiling that cannot be read' => [8 * 1024 ** 3, null, 1],
]);

it('works the count out on this machine, read as the build reads it', function (): void {
    $count = WorkerCount::of(null, ceiling: 2 * 1024 ** 3);

    expect($count)->toBeGreaterThanOrEqual(1)
        ->and($count)->toBeLessThanOrEqual(WorkerCount::MAX);
});

it('leaves a configured count to the application, whatever its memory limit', function (): void {
    expect(WorkerCount::of(6, cpusAnswering(16), memoryAnswering(1024 ** 3), 2 * 1024 ** 3))->toBe(6);
});
