<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

/**
 * `sha256_file`, memoised for the lifetime of one build. The same source file lands in many routes'
 * dependency lists — a shared controller, a base resource, an enum — so freshness checking re-read
 * and re-hashed it once per route. Memoising also gives one build ONE view of each file, rather than
 * a view that can shift under it mid-build.
 *
 * Deliberately an instance and never a static: the memo dies with the {@see FragmentCache} that owns
 * it, and a cache is built per resolution — one console run, one request — so a long-lived process
 * (queue worker, or the viewer generating per request) can never serve a hash a previous run read.
 *
 * @internal
 */
final class FileDigests
{
    /** @var array<string, string|false> */
    private array $digests = [];

    /** The file's digest, or `false` when it cannot be read — same contract as `hash_file`. */
    public function of(string $file): string|false
    {
        return $this->digests[$file] ??= @hash_file('sha256', $file);
    }
}
