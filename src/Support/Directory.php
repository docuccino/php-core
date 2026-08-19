<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Makes a directory, tolerating the one failure that is not one: a concurrent process creating it
 * first. `mkdir` returning false because the directory now exists is a success, which is why the
 * check is repeated afterwards rather than trusted before.
 *
 * @internal
 */
final class Directory
{
    /** False only when the directory is genuinely not there afterwards. */
    public static function ensure(string $path, int $mode = 0755): bool
    {
        return is_dir($path) || @mkdir($path, $mode, true) || is_dir($path);
    }
}
