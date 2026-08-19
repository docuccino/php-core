<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use RuntimeException;

/**
 * A shared recording could not be locked, so nothing was written.
 *
 * Refusing is the only truthful answer: without the lock two workers would read the same file and each
 * write the whole of it back, and the published example would be whichever finished last. The caller
 * turns this into a message naming the runner, which is where the reader can do something about it.
 *
 * @internal
 */
final class UnlockableRecording extends RuntimeException
{
    private function __construct(
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function directory(string $path): self
    {
        return new self($path, sprintf('The lock directory %s could not be created.', $path));
    }

    public static function untrusted(string $path): self
    {
        return new self($path, sprintf('The lock directory %s is not this user\'s, so nothing was read from it or written to it.', $path));
    }

    public static function unopenable(string $path): self
    {
        return new self($path, sprintf('The lock file %s could not be opened.', $path));
    }

    public static function unlockable(string $path): self
    {
        return new self($path, sprintf('%s could not be locked, so this filesystem cannot serialise the writers.', $path));
    }
}
