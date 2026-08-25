<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Support\AtomicFile;
use Docuccino\Core\Support\Directory;
use JsonException;

/**
 * The ledger for a suite split across worker processes: the session lives in a scratch file beside an
 * exclusive lock, and every read-compare-write of a recording happens inside it.
 *
 * A recording is per-operation, so a worker recording one needs nothing from any other worker — only
 * that the two of them do not write over each other. The lock gives that, and
 * {@see RecordedExample::outranks()} gives the rest: it is a total order on the bodies themselves, so
 * the best of a set is the same whichever worker met which member of it, and the surviving file is a
 * function of the responses the suite produced rather than of the order the workers raced in.
 *
 * The lock is a file of its own rather than the recording, for the reason {@see AtomicFile} states:
 * the recording is replaced by a rename, so a lock on it is a lock on a discarded inode. Both it
 * and the session sit under the system temp directory keyed by the recordings directory and the RUN,
 * so a later run of the same suite starts from the file as it stands rather than from what the last
 * one was accumulating, and neither ever appears in the tree an author commits.
 *
 * That directory's name is derivable by anyone with an account on the machine — it has to be, or two
 * workers of one run could not find the same one without talking — so the trust comes from who owns
 * it. It is created private and refused when it turns out to be somebody else's, because the contents
 * of one somebody else made would reach the recordings an author commits, and everything this run
 * recorded would be readable by whoever made it.
 *
 * @internal
 */
final class SharedRecordingLedger extends RecordingLedger
{
    public function __construct(
        private readonly RecordingStore $store,
        private readonly string $runKey,
        private readonly ?string $scratchRoot = null,
    ) {}

    /**
     * @throws UnlockableRecording when the writers cannot be serialised, which is never answered by
     *                             writing anyway
     */
    protected function commit(string $operationId, string $endpoint, RecordedExample $example): void
    {
        $file = RecordingStore::fileNameFor($operationId);

        if ($file === null) {
            return;
        }

        $directory = $this->open();

        $session = $directory.'/'.$file;
        $lock = $session.'.lock';
        $handle = @fopen($lock, 'c');

        if ($handle === false) {
            throw UnlockableRecording::unopenable($lock);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw UnlockableRecording::unlockable($lock);
            }

            $merged = ($this->read($session) ?? RecordingSession::opening($this->store->read($operationId)))->with($example);

            if ($this->store->put($merged->recording($operationId, $endpoint))) {
                $this->write($session, $merged);
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** The scratch directory, made ours or refused. */
    private function open(): string
    {
        $directory = $this->scratch();

        if (! Directory::ensure($directory, 0700)) {
            throw UnlockableRecording::directory($directory);
        }

        if (! self::ours($directory)) {
            throw UnlockableRecording::untrusted($directory);
        }

        return $directory;
    }

    /** Ours: the directory itself rather than a link to one, owned by this user, writable by nobody else. */
    private static function ours(string $directory): bool
    {
        clearstatcache(true, $directory);

        if (is_link($directory)) {
            return false;
        }

        // Nothing to compare an owner against without POSIX — and nothing reaches this ledger without
        // it either, since the run key it is built with is taken from the same extension.
        if (! function_exists('posix_geteuid')) {
            return true;
        }

        $permissions = @fileperms($directory);

        return @fileowner($directory) === posix_geteuid() && $permissions !== false && ($permissions & 0o022) === 0;
    }

    /** The run's own scratch directory: one per recordings directory per run, never inside the tree. */
    private function scratch(): string
    {
        $root = $this->scratchRoot ?? sys_get_temp_dir();
        $run = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $this->runKey);

        return $root.'/docuccino-recordings-'.substr(sha1($this->store->directory), 0, 16).'-'.$run;
    }

    /** The session this run has accumulated, or null when it has not started one — or left a torn file. */
    private function read(string $path): ?RecordingSession
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            // Through the shared reader, exactly as {@see RecordingStore::at()} reads the committed
            // sidecar: a session carries recorded BODIES, and an associative decode reads a `{}` in one
            // back as `[]`. The two files hold the same values and must be read the same way, or which
            // worker won a slot decides whether an example keeps its shape.
            $decoded = RecordedBody::decode($contents);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return RecordingSession::fromArray($decoded);
    }

    /** Written the way the recording itself is, so a worker reading it never sees half of one. */
    private function write(string $path, RecordingSession $session): void
    {
        $json = json_encode($session->toArray());

        if ($json !== false) {
            AtomicFile::write($path, $json);
        }
    }
}
