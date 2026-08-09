<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Confines a user-supplied relative path to a base directory. `#[DescriptionFromFile]` and
 * `info.description.file` both read a project file whose path comes from config or an attribute, so
 * `../../etc/passwd` must never escape the app. Resolution is lexical first — collapsing `.` / `..`
 * without touching the filesystem, which catches traversal even for targets that don't exist — then
 * re-checked through {@see realpath()} when the target does exist, so symlinks can't tunnel out.
 *
 * @internal
 */
final class ConfinedPath
{
    /**
     * The absolute path $relative resolves to under $base, or null when it escapes. A returned path
     * is confined but may not exist: callers treat a read failure as "absent", which is a different
     * thing from this null, meaning "rejected escape".
     */
    public static function resolve(string $base, string $relative): ?string
    {
        $base = self::normalize($base);
        $candidate = self::normalize($base.'/'.ltrim($relative, '/'));

        if (! self::within($base, $candidate)) {
            return null;
        }

        // Symlink escapes: if the target exists, its realpath must land inside the base's realpath.
        $real = realpath($candidate);
        $realBase = realpath($base);
        if ($real !== false && $realBase !== false && ! self::within($realBase, $real)) {
            return null;
        }

        return $candidate;
    }

    private static function within(string $base, string $candidate): bool
    {
        return $candidate === $base || str_starts_with($candidate, $base.'/');
    }

    /**
     * Collapse `.` and `..` lexically, keeping any leading `/`. Public so the adapter's base-path
     * relativisation (the inverse direction) shares this normalizer instead of re-rolling it.
     */
    public static function normalize(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return ($absolute ? '/' : '').implode('/', $segments);
    }
}
