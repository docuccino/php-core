<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Support\AvailableCpus;
use Docuccino\Core\Support\AvailableMemory;

/**
 * The most workers a build may hand its operations to ({@see BuildWorkers}): the count the application
 * configured, and otherwise as many as this process's CPUs and its container's memory allow, up to {@see MAX}.
 * What a build then uses also depends on how many operations are cold, which only the build knows.
 *
 * @internal
 */
final class WorkerCount
{
    /**
     * The most the automatic count reaches. Every worker walks the files its operations share with other
     * workers' again, so past this each one adds shared work nearly as fast as it takes a share of the rest.
     */
    public const int MAX = 8;

    /**
     * What a count that was written but cannot be used builds with: one process. Whoever wrote it may have
     * been turning forking off, and forking is the half of that choice that can go wrong.
     */
    public const int UNUSABLE = 1;

    /**
     * @param  mixed  $configured  what the application wrote: null works the count out, a whole number is used
     *                             as it stands (below one, as one), and anything else is {@see UNUSABLE}
     * @param  int|null  $ceiling  the bytes each process may use, its `memory_limit`; null where it cannot be read
     */
    public static function of(mixed $configured, ?AvailableCpus $cpus = null, ?AvailableMemory $memory = null, ?int $ceiling = null): int
    {
        if ($configured !== null) {
            return is_int($configured) ? max(1, $configured) : self::UNUSABLE;
        }

        $fitting = self::fitting(($memory ?? AvailableMemory::here())->limit(), $ceiling);

        return max(1, min(self::MAX, ($cpus ?? AvailableCpus::here())->count() ?? 1, $fitting));
    }

    /**
     * The workers that fit beside the build in a memory limit, each counted at the whole ceiling it may use:
     * nothing stops one growing that far, and past the limit the kernel kills a process, the build's own
     * included. Unbounded where no limit is set, and none where the ceiling is unknown or unlimited.
     */
    private static function fitting(?int $limit, ?int $ceiling): int
    {
        if ($limit === null) {
            return PHP_INT_MAX;
        }

        return $ceiling === null || $ceiling < 1 ? 0 : intdiv($limit, $ceiling) - 1;
    }
}
