<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

/**
 * What a walk of one coverage-log directory found: the logs under it, and every directory in the tree
 * it could not open.
 *
 * The second list is the whole reason this is a value rather than a list of files. A subdirectory that
 * cannot be read is a shard's worth of coverage missing, and reading it as "nothing here" is exactly how
 * a gate quietly measures three of four shards — so the walk carries the failure up by name, and
 * {@see CoverageMerge} reports the directory that actually refused rather than the root it was under.
 *
 * @internal
 */
final readonly class CoverageScan
{
    /**
     * @param  list<string>  $files  log files found, sorted
     * @param  list<string>  $missing  directories in the tree that could not be read
     */
    public function __construct(public array $files, public array $missing) {}
}
