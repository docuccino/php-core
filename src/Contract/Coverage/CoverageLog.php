<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

use Docuccino\Core\Support\Directory;

/**
 * One process's share of a coverage run: a file of entries, appended to as the suite meets them.
 *
 * There is no lock here, and that is the whole difference from the response recorder's shared ledger.
 * A recording is a value several workers contest — the best body for one operation — so they have to
 * take turns over one file. Coverage is a SET UNION: an entry was exercised or it was not, two workers
 * seeing the same one adds nothing to reconcile, and a worker's own file has exactly one writer. So
 * every process appends to a file of its own and {@see CoverageMerge} unions them after the run,
 * where the timing question a worker cannot answer no longer needs asking.
 *
 * @internal
 */
final readonly class CoverageLog
{
    public const string EXTENSION = '.ids';

    private const string AT = '@';

    /**
     * The shape of an operation id, spelled once because both entry forms are built out of it. The
     * algorithm version is read loosely: a log a newer identity algorithm wrote is still a log.
     */
    private const string ID = 'op:v\d+:[0-9a-z]{16}';

    private const string ID_PATTERN = '/^'.self::ID.'$/D';

    /** The same id with the status it answered, which is the entry a response assertion writes. */
    private const string RESPONSE_PATTERN = '/^'.self::ID.self::AT.'[1-5]\d{2}$/D';

    public function __construct(public string $directory, public string $file) {}

    /**
     * The log this process writes.
     *
     * The name carries the worker token wherever the runner sets one, because `w3.…` is worth more to
     * whoever opens the directory than a hash is — but it carries the pid and four random bytes BESIDE
     * it rather than instead of them, because a token is not unique. Run `--shard=1/4` and
     * `--shard=2/4` on one machine and both have a worker `1`; one silently overwriting the other is
     * exactly the false gap this feature exists to stop. Nothing is detected, either: a runner that
     * sets no token is the ordinary single-process case, not an error, and a runner nobody has heard
     * of participates by writing a file like everyone else.
     *
     * The cost of unique-per-process names is that a second run ADDS files rather than replacing them,
     * which is why `docuccino:coverage --reset` exists and why the documented recipe runs it first. No
     * determinism is spent: a name never reaches a document, and the merged report is a sorted union
     * that does not know what the files were called.
     */
    public static function for(string $directory, ?string $worker = null): self
    {
        return new self($directory, sprintf(
            '%s.%d.%s%s',
            self::slug($worker ?? 'main'),
            getmypid() === false ? 0 : getmypid(),
            // random_int over bin2hex(random_bytes(…)): the oldest analyser CI resolves types
            // random_bytes as mixed, and it carries more entropy per character besides.
            dechex(random_int(0, PHP_INT_MAX)),
            self::EXTENSION,
        ));
    }

    public function path(): string
    {
        return $this->directory.'/'.$this->file;
    }

    /**
     * Append entries to this process's file, one per line. False when the directory or the write refused,
     * which the caller treats as "this run logged nothing" rather than as a failed test.
     *
     * @param  list<string>  $entries
     */
    public function append(array $entries): bool
    {
        if ($entries === []) {
            return true;
        }

        if (! Directory::ensure($this->directory)) {
            return false;
        }

        return @file_put_contents($this->path(), implode("\n", $entries)."\n", FILE_APPEND) !== false;
    }

    /**
     * The line for one thing the suite reached, or null when neither half is the shape.
     *
     * Two forms, and the difference is what the run PROVED. `op:…@422` says that documented response
     * was exercised; a bare `op:…` says only that the operation was reached, which is all a
     * request-only assertion, or a log an older release wrote, can honestly claim. A status the grammar
     * cannot carry widens to the bare form rather than dropping the line, so an odd status code costs
     * the response row and never the operation.
     */
    public static function entry(string $id, ?int $status = null): ?string
    {
        if ($status !== null && self::isEntry($id.self::AT.$status)) {
            return $id.self::AT.$status;
        }

        return self::isEntry($id) ? $id : null;
    }

    /**
     * Whether a line is an entry and not something else — the log's only grammar, read at both ends so
     * a writer never appends what the reader would condemn.
     *
     * Both halves are fixed-shape, which is what makes the check worth having. A worker killed mid-write
     * leaves an ASCII PREFIX of one, and a prefix carries no control character to give itself away: it
     * would merge as an ordinary entry, match no documented operation, and quietly undercount. Held to
     * the shape instead, a truncated line makes its file unreadable, which is what the merge already
     * refuses on.
     */
    public static function isEntry(string $value): bool
    {
        return preg_match(self::ID_PATTERN, $value) === 1 || preg_match(self::RESPONSE_PATTERN, $value) === 1;
    }

    /**
     * An entry split into the operation it names and the status it answered, null only for nothing at
     * all.
     *
     * It splits rather than refuses: {@see isEntry()} is the guard, and it stands at the log's two ends
     * where a bad line means a torn FILE. Here a bad line means one line, and an id that is not an
     * operation's matches no operation in the report either — so a report of a hand-assembled list is
     * short by that line and never wrong about the rest.
     *
     * @return array{id: string, status: int|null}|null
     */
    public static function parse(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $at = strrpos($value, self::AT);

        return $at === false || $at === 0 || preg_match('/^\d+$/D', substr($value, $at + 1)) !== 1
            ? ['id' => $value, 'status' => null]
            : ['id' => substr($value, 0, $at), 'status' => (int) substr($value, $at + 1)];
    }

    /**
     * Every log file under a directory, sorted, plus every directory in the tree that could not be read.
     *
     * It descends subdirectories, so a directory of downloaded CI artifacts merges as one path — and a
     * subdirectory it cannot open is carried up BY NAME rather than folded into "found nothing", because
     * a shard nobody could read is not a shard that ran clean. It refuses to descend a LINK: `is_link()`
     * is asked before `is_dir()`, which answers true for a link to one. That matters because the reset
     * path deletes the files this reports.
     */
    public static function scan(string $directory): CoverageScan
    {
        if (! is_dir($directory) || is_link($directory)) {
            return new CoverageScan([], [$directory]);
        }

        $names = @scandir($directory);

        if ($names === false) {
            return new CoverageScan([], [$directory]);
        }

        sort($names);

        $files = [];
        $missing = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.'/'.$name;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $below = self::scan($path);
                $files = [...$files, ...$below->files];
                $missing = [...$missing, ...$below->missing];

                continue;
            }

            if (str_ends_with($name, self::EXTENSION)) {
                $files[] = $path;
            }
        }

        return new CoverageScan($files, $missing);
    }

    /** A worker token as a filename fragment: the runner chose the string, not us. */
    private static function slug(string $value): string
    {
        $slug = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $value), 0, 32);

        return $slug === '' ? 'main' : $slug;
    }
}
