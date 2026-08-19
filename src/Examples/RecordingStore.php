<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\AtomicFile;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\Directory;
use JsonException;

/**
 * The directory of committed recordings: one file per operation, named after its stable id.
 *
 * Reading one is all the document build ever does with a test suite's traffic — no application code
 * runs, no database is touched. The file is the whole seam, and it is committed precisely so a human
 * reviews what goes into the document before it does.
 *
 * @internal
 */
final readonly class RecordingStore
{
    /** `op-v1-abcdefgh12345678.json` — the operation id with its separators made filename-safe. */
    private const string FILE_PATTERN = '/^op-(v\d+)-([0-9a-z]{16})\.json$/D';

    private const string ID_PATTERN = '/^op:(v\d+):([0-9a-z]{16})$/D';

    public function __construct(
        public string $directory,
    ) {}

    /**
     * The store this document reads, or null when it names no recordings directory — or names one
     * outside the application, which {@see ConfinedPath} refuses the same way it refuses one for
     * `content.dir`.
     */
    public static function for(DocumentConfig $document, string $basePath): ?self
    {
        $configured = $document->recordingsDir();

        if ($configured === null) {
            return null;
        }

        $resolved = ConfinedPath::configuredDir($basePath, $configured);

        return $resolved === null ? null : new self($resolved);
    }

    /** Where this operation's recording lives, or null when the id is not an operation id. */
    public function pathFor(string $operationId): ?string
    {
        $file = self::fileNameFor($operationId);

        return $file === null ? null : $this->directory.'/'.$file;
    }

    /** The recording for one operation, or null when there is none — or when the file is not one. */
    public function read(string $operationId): ?ExampleRecording
    {
        $path = $this->pathFor($operationId);

        return $path === null ? null : self::at($path);
    }

    /** The recording a file holds, or null when it does not hold one. */
    public static function at(string $path): ?ExampleRecording
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $decoded = RecordedBody::decode($contents);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return ExampleRecording::fromArray($decoded);
    }

    /**
     * Write a recording, atomically — a suite is many processes' worth of writes and a half-written
     * file is one a build would report as unreadable. False when the directory or the write refused.
     */
    public function put(ExampleRecording $recording): bool
    {
        $path = $this->pathFor($recording->operationId);
        $json = RecordedBody::encode($recording->toArray());

        if ($path === null || $json === null) {
            return false;
        }

        // Bytes that are already there are not written again: a recording is a committed file, and a
        // suite that changed nothing should leave the working tree — and every mtime in it — alone.
        if (@file_get_contents($path) === $json) {
            return true;
        }

        if (! Directory::ensure($this->directory)) {
            return false;
        }

        return AtomicFile::write($path, $json);
    }

    /**
     * Every recording file in the directory, sorted — so what a reader is told about them never
     * depends on the order the filesystem happened to hand them over.
     *
     * @return list<string>
     */
    public function fileNames(): array
    {
        $entries = @scandir($this->directory);

        if ($entries === false) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if (preg_match(self::FILE_PATTERN, $entry) === 1) {
                $files[] = $entry;
            }
        }

        sort($files);

        return $files;
    }

    public static function fileNameFor(string $operationId): ?string
    {
        if (preg_match(self::ID_PATTERN, $operationId, $matches) !== 1) {
            return null;
        }

        return 'op-'.$matches[1].'-'.$matches[2].'.json';
    }

    public static function operationIdFor(string $fileName): ?string
    {
        if (preg_match(self::FILE_PATTERN, $fileName, $matches) !== 1) {
            return null;
        }

        return 'op:'.$matches[1].':'.$matches[2];
    }
}
