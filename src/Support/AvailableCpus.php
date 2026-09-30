<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Closure;

/**
 * How many CPUs this process may actually run on: the ones its affinity allows, cut down to its cgroup's CPU
 * quota — which is what a container started with `--cpus=2` sees, however many the host has. Null where
 * nothing answers, so a caller can fall back to one.
 *
 * @internal
 */
final readonly class AvailableCpus
{
    /**
     * @param  Closure(string): (string|null)  $read  a file's contents, or null where it cannot be read
     * @param  Closure(string): (string|null)  $run  a command's output, or null where it cannot be run
     */
    public function __construct(
        private Closure $read,
        private Closure $run,
    ) {}

    /** The reader for this machine, quiet on every failure: under a host that throws for warnings, one is fatal. */
    public static function here(): self
    {
        return new self(
            static fn (string $path): ?string => is_string($contents = @file_get_contents($path)) ? $contents : null,
            static fn (string $command): ?string => function_exists('shell_exec') && is_string($output = @shell_exec($command.' 2>/dev/null')) ? $output : null,
        );
    }

    public function count(): ?int
    {
        $online = $this->affinity() ?? $this->processors() ?? $this->sysctl();
        $quota = $this->quota();

        if ($online === null) {
            return $quota;
        }

        return $quota === null ? $online : min($online, $quota);
    }

    /** CPUs in a list such as `0-3,8,10-11`, as `/proc/self/status` writes the affinity mask; null where it is not one. */
    public static function inList(string $list): ?int
    {
        $count = 0;

        foreach (explode(',', trim($list)) as $range) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', trim($range), $m) !== 1) {
                return null;
            }

            $count += isset($m[2]) ? (int) $m[2] - (int) $m[1] + 1 : 1;
        }

        return $count > 0 ? $count : null;
    }

    /**
     * Whole CPUs a CFS quota allows, rounded up so a fractional quota still gets the CPU it partly has — or
     * null where the quota is unlimited (`max`, or `-1` in cgroup v1) or not a quota at all.
     */
    public static function inQuota(string $quota, string $period): ?int
    {
        if (! ctype_digit(trim($quota)) || ! ctype_digit(trim($period)) || (int) $period === 0) {
            return null;
        }

        return max(1, (int) ceil((int) $quota / (int) $period));
    }

    private function affinity(): ?int
    {
        $status = ($this->read)('/proc/self/status');
        if ($status === null || preg_match('/^Cpus_allowed_list:\s*(\S+)/m', $status, $m) !== 1) {
            return null;
        }

        return self::inList($m[1]);
    }

    private function processors(): ?int
    {
        $info = ($this->read)('/proc/cpuinfo');
        $count = $info === null ? 0 : preg_match_all('/^processor\s*:/m', $info);

        return $count > 0 ? $count : null;
    }

    private function sysctl(): ?int
    {
        $output = ($this->run)('sysctl -n hw.logicalcpu');

        return $output !== null && ctype_digit(trim($output)) && (int) trim($output) > 0 ? (int) trim($output) : null;
    }

    private function quota(): ?int
    {
        $v2 = ($this->read)('/sys/fs/cgroup/cpu.max');
        if ($v2 !== null) {
            $parts = preg_split('/\s+/', trim($v2)) ?: [];

            return count($parts) === 2 ? self::inQuota($parts[0], $parts[1]) : null;
        }

        $quota = ($this->read)('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
        $period = ($this->read)('/sys/fs/cgroup/cpu/cpu.cfs_period_us');

        return $quota === null || $period === null ? null : self::inQuota($quota, $period);
    }
}
