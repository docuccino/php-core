<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

/**
 * Every coverage log under a set of directories, unioned — the whole-suite view no worker and no shard
 * can take on its own.
 *
 * A union has no order and no first writer, so the merged list is a function of what ran and nothing
 * else: the same ids come back whatever the worker count was, whichever file each id was seen in, and
 * whatever order the directories were named in. An id in twenty files counts once.
 *
 * What it will NOT do is answer from part of the input. A directory it cannot read — absent, or there
 * and refusing to open, at the top of a tree or nested anywhere in one — a directory that holds no log
 * at all, and a file that does not read back as ids each mean the merge is INCOMPLETE, and a gate that
 * quietly measured three of four shards is worse than no gate: those are reported and {@see complete()}
 * is false, rather than being averaged away into a number.
 *
 * The one thing it cannot notice is a directory nobody NAMED. Four shards under one downloaded tree are
 * one `--path`, and a shard whose artifact never arrived is then not a missing directory but a
 * subdirectory that was never there to miss — which is why the documented CI recipe names each shard's
 * directory as a `--path` of its own.
 *
 * @internal
 */
final readonly class CoverageMerge
{
    /**
     * @param  list<string>  $ids  every operation id exercised, deduped and sorted
     * @param  list<string>  $files  the log files that were read
     * @param  list<string>  $missing  directories that could not be read at all, nested ones included
     * @param  list<string>  $empty  directories that hold no coverage log
     * @param  list<string>  $unreadable  log files that could not be read, or hold something else
     * @param  int  $span  seconds between the oldest and newest log read, 0 when nothing was read
     */
    private function __construct(
        public array $ids,
        public array $files,
        public array $missing,
        public array $empty,
        public array $unreadable,
        public int $span,
    ) {}

    /**
     * @param  list<string>  $directories
     */
    public static function of(array $directories): self
    {
        $seen = [];
        $files = [];
        $missing = [];
        $empty = [];
        $unreadable = [];
        $times = [];

        foreach ($directories as $directory) {
            $scan = CoverageLog::scan($directory);
            $missing = [...$missing, ...$scan->missing];

            // A directory that refused to open is already named; calling it empty as well would report
            // one absence twice and, worse, as the wrong kind of absence.
            if ($scan->files === []) {
                if ($scan->missing === []) {
                    $empty[] = $directory;
                }

                continue;
            }

            foreach ($scan->files as $log) {
                $ids = self::read($log);

                if ($ids === null) {
                    $unreadable[] = $log;

                    continue;
                }

                $files[] = $log;
                $time = @filemtime($log);

                if ($time !== false) {
                    $times[] = $time;
                }

                foreach ($ids as $id) {
                    $seen[$id] = true;
                }
            }
        }

        $ids = array_keys($seen);
        sort($ids);
        sort($files);

        return new self($ids, $files, $missing, $empty, $unreadable, $times === [] ? 0 : max($times) - min($times));
    }

    /** Whether every directory asked for contributed, which is the only state a gate may read. */
    public function complete(): bool
    {
        return $this->missing === [] && $this->empty === [] && $this->unreadable === [];
    }

    /**
     * The ids one log file holds, or null when it holds something that is not one.
     *
     * A file is written by appending whole ids and by nothing else, so a line that is not one means the
     * file was torn or was never a log — and either way it is a shard's worth of coverage silently
     * missing, the failure this whole class refuses to paper over. The line is held to the id SHAPE
     * ({@see CoverageLog::isId()}) rather than merely to being printable, because the likeliest tear is
     * a worker killed part way through a write, which leaves a prefix of an id that would otherwise
     * merge as an id, match nothing, and undercount in silence. An EMPTY file is not torn: it is a
     * worker that exercised nothing, which is an ordinary thing for a worker to do.
     *
     * @return list<string>|null
     */
    private static function read(string $path): ?array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $ids = [];
        foreach (explode("\n", $contents) as $line) {
            $id = rtrim($line, "\r");

            if ($id === '') {
                continue;
            }

            if (! CoverageLog::isId($id)) {
                return null;
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
