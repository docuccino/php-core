<?php

declare(strict_types=1);

use Docuccino\Core\Support\AvailableCpus;

/*
 * The CPUs a process may actually use: its affinity, cut to its cgroup's CPU quota — which is what a
 * container started with `--cpus=2` gets, however many the host has.
 */

/**
 * A machine made of the files and commands given, and nothing else.
 *
 * @param  array<string, string>  $files
 * @param  array<string, string>  $commands
 */
function machineWith(array $files, array $commands = []): AvailableCpus
{
    return new AvailableCpus(
        static fn (string $path): ?string => $files[$path] ?? null,
        static fn (string $command): ?string => $commands[$command] ?? null,
    );
}

it('counts the CPUs in an affinity list', function (string $list, ?int $count): void {
    expect(AvailableCpus::inList($list))->toBe($count);
})->with([
    'one' => ['0', 1],
    'a range' => ['0-7', 8],
    'ranges and singles' => ['0-3,8,10-11', 7],
    'padded' => [" 0-1\n", 2],
    'not a list' => ['all', null],
    'a broken range' => ['0-', null],
    'nothing' => ['', null],
]);

it('reads the whole CPUs a quota allows, rounding a part up', function (string $quota, string $period, ?int $count): void {
    expect(AvailableCpus::inQuota($quota, $period))->toBe($count);
})->with([
    'two' => ['200000', '100000', 2],
    'a part of one' => ['50000', '100000', 1],
    'one and a half' => ['150000', '100000', 2],
    'unlimited (cgroup v2)' => ['max', '100000', null],
    'unlimited (cgroup v1)' => ['-1', '100000', null],
    'no period' => ['200000', '0', null],
]);

it('cuts the affinity to the cgroup v2 quota', function (): void {
    $cpus = machineWith([
        '/proc/self/status' => "Name:\tphp\nCpus_allowed_list:\t0-15\n",
        '/sys/fs/cgroup/cpu.max' => "200000 100000\n",
    ]);

    expect($cpus->count())->toBe(2);
});

it('reads a cgroup v1 quota where there is no v2 one', function (): void {
    $cpus = machineWith([
        '/proc/self/status' => "Cpus_allowed_list:\t0-15\n",
        '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' => "300000\n",
        '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => "100000\n",
    ]);

    expect($cpus->count())->toBe(3);
});

it('keeps the affinity where the quota is unlimited', function (): void {
    $cpus = machineWith([
        '/proc/self/status' => "Cpus_allowed_list:\t0-5\n",
        '/sys/fs/cgroup/cpu.max' => "max 100000\n",
    ]);

    expect($cpus->count())->toBe(6);
});

it('falls back to cpuinfo, then to sysctl, then to nothing', function (): void {
    expect(machineWith(['/proc/cpuinfo' => "processor\t: 0\nflags\t: x\n\nprocessor\t: 1\n"])->count())->toBe(2)
        ->and(machineWith([], ['sysctl -n hw.logicalcpu' => "10\n"])->count())->toBe(10)
        ->and(machineWith([], ['sysctl -n hw.logicalcpu' => "no such key\n"])->count())->toBeNull()
        ->and(machineWith([])->count())->toBeNull();
});

it('answers a quota alone where nothing counts the CPUs', function (): void {
    expect(machineWith(['/sys/fs/cgroup/cpu.max' => "400000 100000\n"])->count())->toBe(4);
});

it('reads this machine without failing', function (): void {
    $count = AvailableCpus::here()->count();

    expect($count === null || $count >= 1)->toBeTrue();
});

it('reads this machine without a word where the files are out of its reach', function (): void {
    // Outside open_basedir every read warns, and a host that turns warnings into exceptions (Laravel does)
    // would end the build while it was only asking how many workers to start. In a process of its own,
    // because open_basedir can be narrowed and never widened again.
    $run = runPhp(<<<'PHP'
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $level) !== 0) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            return false;
        });

        // Loaded while the autoloader can still reach them.
        class_exists(Docuccino\Core\Support\AvailableCpus::class);
        class_exists(Docuccino\Core\Support\AvailableMemory::class);

        ini_set('open_basedir', sys_get_temp_dir());
        $count = Docuccino\Core\Support\AvailableCpus::here()->count();
        $memory = Docuccino\Core\Support\AvailableMemory::here()->limit();

        echo $count === null || $count >= 1 ? 'counted' : 'miscounted', $memory === null || $memory > 0 ? ' measured' : ' mismeasured';
        PHP, timeout: 20.0);

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($run['output'])->toBe('counted measured');
});
