<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Write a file so no reader ever sees half of one: a temp file in the same directory, then a rename.
 *
 * It matters most where a write can be interrupted — `docuccino:watch` re-exports on every save, and
 * Ctrl+C reaches the build it is running, so a plain `file_put_contents` would eventually leave a
 * truncated artifact behind. The temp name carries the pid and 63 bits of randomness, so two
 * processes writing the same target cannot land on each other's — and it is created with `x`, which
 * refuses to open anything already there rather than writing through a symlink somebody left in the
 * way.
 *
 * Note what the rename does to a lock: it replaces the target's inode, so a lock held ON the file
 * being written is a lock on something the next writer has already thrown away. Anything serialising
 * writers here has to lock a file of its own.
 *
 * @internal
 */
final class AtomicFile
{
    /** False when the write or the rename failed, leaving whatever was already there untouched. */
    public static function write(string $path, string $contents): bool
    {
        // random_int over bin2hex(random_bytes(…)): an unambiguous int return type for every analyser
        // version we support, and twice the entropy.
        $temp = $path.'.'.getmypid().'.'.dechex(random_int(0, PHP_INT_MAX)).'.tmp';
        $handle = @fopen($temp, 'xb');

        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, $contents);
        @fclose($handle);

        if ($written === strlen($contents) && @rename($temp, $path)) {
            return true;
        }

        @unlink($temp);

        return false;
    }
}
