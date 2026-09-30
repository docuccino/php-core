<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Closure;
use Throwable;

/**
 * Hands a build's work to forked copies of this process: a cold build's operations ({@see run()}), and whole
 * tasks whose answers come back as data ({@see later()}). A worker adds time and nothing else, since a warm
 * build already equals a cold one and a task reads nothing that changes before its answer is asked for, so it
 * answers the same wherever and whenever it runs (design §3). What a worker leaves undone, for whatever reason
 * it stopped, the build does itself, so a worker may always simply stop.
 *
 * @internal
 */
final class BuildWorkers
{
    /** Cold operations each worker has to be worth: below it, forking costs more than it saves. */
    public const int MIN_OPERATIONS = 8;

    /** The host's limit, asked once: it may read the machine to answer. */
    private ?int $limitAnswer = null;

    /** Workers {@see later()} has started and not yet reaped. */
    private int $live = 0;

    private int $answered = 0;

    /**
     * @param  Closure(): int  $limit  the most workers this build may use; 1 means build everything here
     * @param  (Closure(): void)|null  $beforeFork  run in this process before it forks: once for {@see run()}'s
     *                                              workers, and before each worker {@see later()} starts; one
     *                                              that throws leaves the build to work alone
     * @param  (Closure(): void)|null  $inWorker  run in each worker once, before it does anything else
     */
    public function __construct(
        private readonly Closure $limit,
        private readonly ?Closure $beforeFork = null,
        private readonly ?Closure $inWorker = null,
    ) {}

    /** Workers for nobody: every operation is built where the build runs. */
    public static function none(): self
    {
        return new self(static fn (): int => 1);
    }

    /** Whether this process can fork a worker at all. */
    public static function forkable(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid') && function_exists('posix_kill') && function_exists('posix_getpid') && function_exists('posix_getppid');
    }

    /** Whether this build may fork at all, before anything is counted. */
    public function mayFork(): bool
    {
        return self::forkable() && $this->limit() >= 2;
    }

    /** How many workers `$operations` cold operations warrant; 1 means build them here. */
    public function for(int $operations): int
    {
        if (! $this->mayFork()) {
            return 1;
        }

        return max(1, min($this->limit(), intdiv($operations, self::MIN_OPERATIONS)));
    }

    /**
     * `$task` started in a worker of its own, at most one fewer than the limit at once, and what answers for it:
     * the worker's answer where it gave a whole one, the task run here otherwise — at the first ask either way.
     *
     * @template T of array<mixed>|scalar|null
     *
     * @param  Closure(): T  $task
     * @return Closure(): T
     */
    public function later(Closure $task): Closure
    {
        $answer = self::forkable() && $this->live < $this->limit() - 1 ? $this->start($task) : null;

        return ($answer ?? new TaskAnswer($task))->__invoke(...);
    }

    /** How many of {@see later()}'s answers came from the worker started for each, rather than being made here. */
    public function answered(): int
    {
        return $this->answered;
    }

    /**
     * Build every job, across `$count` workers, and return once each has exited.
     *
     * @template T
     *
     * @param  list<list<T>>  $units  each claimed whole by one worker, offered in this order
     * @param  Closure(T): void  $build
     * @param  string|null  $scratch  a directory of the build's own the workers write into ({@see directory()}),
     *                                which a worker whose build has gone takes away with the claims
     */
    public function run(int $count, array $units, Closure $build, ?string $scratch = null): void
    {
        $claims = self::directory('claims');
        if ($claims === null) {
            return;
        }

        try {
            if (! $this->settled()) {
                return;
            }

            $parent = posix_getpid();
            $inWorker = $this->inWorker;
            $life = static fn () => self::serve($parent, $claims, $scratch, $units, $build, $inWorker);

            $workers = [];
            for ($i = 0; $i < $count; $i++) {
                $pid = self::fork();
                if ($pid === -1) {
                    break;
                }

                if ($pid === 0) {
                    self::work($life);
                }

                $workers[] = $pid;
            }

            foreach ($workers as $pid) {
                pcntl_waitpid($pid, $status);
            }
        } finally {
            self::remove($claims);
        }
    }

    /**
     * A directory of this run's own under the system temp directory, or null where none can be made. Named for
     * the process that made it as well, so what a run left behind is told apart from what another run beside
     * it is still using.
     */
    public static function directory(string $purpose): ?string
    {
        // random_int over bin2hex(random_bytes(…)): the oldest analyser CI runs types random_bytes as mixed.
        $directory = sys_get_temp_dir().'/docuccino-'.$purpose.'-'.getmypid().'-'.dechex(random_int(0, PHP_INT_MAX));

        return @mkdir($directory, 0700) ? $directory : null;
    }

    /**
     * Take a {@see directory()} away: flat, dotfiles included (the fragment cache drops a `.gitignore` into
     * every directory it writes), and quietly, since another process may be removing it at the same time.
     */
    public static function remove(string $directory): void
    {
        foreach (@scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($directory.'/'.$entry);
            }
        }

        @rmdir($directory);
    }

    private function limit(): int
    {
        return $this->limitAnswer ??= ($this->limit)();
    }

    /**
     * The answer to `$task` from a worker started for it, or null where none could be started: the host could
     * not settle the process, no channel could be opened to it, or the machine refused the fork.
     *
     * @template T of array<mixed>|scalar|null
     *
     * @param  Closure(): T  $task
     * @return TaskAnswer<T>|null
     */
    private function start(Closure $task): ?TaskAnswer
    {
        $channel = $this->settled() ? @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP) : false;
        if ($channel === false) {
            return null;
        }

        [$ours, $theirs] = $channel;
        // Neither end gives up, whatever `default_socket_timeout` says: the build waits for a worker as long as
        // its task takes, as it waits for one under run(), and a worker's answer waits for the build to ask.
        stream_set_timeout($ours, -1);
        stream_set_timeout($theirs, -1);

        $inWorker = $this->inWorker;
        $life = static fn () => self::send($theirs, $ours, $task, $inWorker);

        $pid = self::fork();
        if ($pid === 0) {
            self::work($life);
        }

        fclose($theirs);
        if ($pid === -1) {
            return null;
        }

        $this->live++;

        return new TaskAnswer($task, $pid, $ours, function (bool $used): void {
            $this->live--;
            $this->answered += $used ? 1 : 0;
        });
    }

    /** Whether the host settled this process for a fork; one that could not has the build work alone. */
    private function settled(): bool
    {
        try {
            if ($this->beforeFork !== null) {
                ($this->beforeFork)();
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A new worker's pid, 0 in the worker, or -1 where the machine refused. Refusing warns, and a host that
     * turns warnings into exceptions (Laravel does) would end the build where it should carry on alone.
     */
    private static function fork(): int
    {
        return @pcntl_fork();
    }

    /**
     * One worker's life, whichever kind: made quiet, then `$life`, then an end without running any of this
     * process's shutdown work — the worker is a copy of the build, so that is the build's to run, once.
     *
     * @param  Closure(): void  $life
     */
    private static function work(Closure $life): never
    {
        self::quiet();
        $life();
        self::end();
    }

    /**
     * Make a worker end without a word however it ends. A fatal error still runs the shutdown functions the
     * worker inherited, so a host silences its own ({@see $inWorker}); this one runs after them and ends the
     * worker there, before anything registered later and before module shutdown, where some extensions hang
     * a forked child (grpc without fork support). PHP's own report of the error is turned off as well.
     */
    private static function quiet(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        register_shutdown_function(self::end(...));
    }

    /**
     * Everything a worker under {@see run()} does short of ending: the host's hook, then units until none are
     * left or the build it was forked from is gone — in which case it takes away what that build no longer can.
     *
     * @template T
     *
     * @param  list<list<T>>  $units
     * @param  Closure(T): void  $build
     */
    private static function serve(int $parent, string $claims, ?string $scratch, array $units, Closure $build, ?Closure $inWorker): void
    {
        try {
            if ($inWorker !== null) {
                $inWorker();
            }

            self::drain($parent, $claims, $units, $build);
        } catch (Throwable) {
            // A worker may always stop.
        }

        if (self::orphaned($parent)) {
            self::remove($claims);
            if ($scratch !== null) {
                self::remove($scratch);
            }
        }
    }

    /**
     * Everything a worker under {@see later()} does short of ending: the host's hook, the task, and its answer
     * sent whole ({@see TaskAnswer::frame()}). A task that throws sends nothing, which the build answers for.
     *
     * @param  resource  $channel
     * @param  resource  $buildEnd  the build's end of the channel, which the worker lets go of first: held, it
     *                              would keep a worker whose build has gone waiting to send for good
     * @param  Closure(): mixed  $task
     */
    private static function send(mixed $channel, mixed $buildEnd, Closure $task, ?Closure $inWorker): void
    {
        fclose($buildEnd);

        try {
            if ($inWorker !== null) {
                $inWorker();
            }

            fwrite($channel, TaskAnswer::frame(serialize($task())));
        } catch (Throwable) {
            // A worker may always stop.
        }
    }

    /**
     * Claim units until none are left, building each one claimed, and stop at the next job once the build is
     * gone — a unit claimed as it went is one nobody is left to build.
     *
     * @template T
     *
     * @param  list<list<T>>  $units
     * @param  Closure(T): void  $build
     */
    private static function drain(int $parent, string $claims, array $units, Closure $build): void
    {
        foreach ($units as $index => $unit) {
            if (! self::claim($claims, $index)) {
                continue;
            }

            foreach ($unit as $job) {
                if (self::orphaned($parent)) {
                    return;
                }

                $build($job);
            }
        }
    }

    /** Whether the build this worker was forked from has gone, and the worker with it has nobody to work for. */
    private static function orphaned(int $parent): bool
    {
        return posix_getppid() !== $parent;
    }

    /**
     * Whether this worker is the first to ask for unit `$index`. Creating the claim is atomic; looking first
     * only spares the warning a claim already taken raises, which a host's error handler is handed either way.
     */
    private static function claim(string $claims, int $index): bool
    {
        $path = $claims.'/'.$index;
        $handle = file_exists($path) ? false : @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    private static function end(): never
    {
        posix_kill(posix_getpid(), SIGKILL);

        exit(1);
    }
}
