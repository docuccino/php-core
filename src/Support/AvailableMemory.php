<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Closure;

/**
 * How much memory this process's cgroup allows it — what a container started with `--memory=2g` is held to,
 * however much the host has. Null where no limit is set or none can be read, which is every machine that is
 * not a container.
 *
 * @internal
 */
final readonly class AvailableMemory
{
    /** Digits that always fit a 64-bit int; cgroup v1 writes "no limit" as a number wider than that. */
    private const int MAX_DIGITS = 18;

    /**
     * @param  Closure(string): (string|null)  $read  a file's contents, or null where it cannot be read
     */
    public function __construct(private Closure $read) {}

    /** The reader for this machine. */
    public static function here(): self
    {
        return new self(static fn (string $path): ?string => is_string($contents = @file_get_contents($path)) ? $contents : null);
    }

    /** Bytes the cgroup allows: v2's `memory.max`, else v1's `memory.limit_in_bytes`. */
    public function limit(): ?int
    {
        $limit = ($this->read)('/sys/fs/cgroup/memory.max') ?? ($this->read)('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        if ($limit === null) {
            return null;
        }

        // `max` in v2, and a figure too wide to be one in v1, both mean no limit.
        $limit = trim($limit);

        return ctype_digit($limit) && strlen($limit) <= self::MAX_DIGITS ? (int) $limit : null;
    }
}
