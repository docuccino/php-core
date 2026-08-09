<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Confines a user-supplied relative path to a base directory (security L2): `#[DescriptionFromFile]`
 * and `info.description.file` both read a project file whose path comes from config/attributes, so a
 * `../../etc/passwd` value must never escape the app. Resolution is lexical first (collapsing `.` /
 * `..` without touching the filesystem, which rejects traversal even for non-existent targets) and,
 * when the target exists, re-checked through {@see realpath()} so a symlink cannot tunnel out either.
 *
 * @internal
 */
final class ConfinedPath
{
    /**
     * The absolute path $relative resolves to under $base, or null when it escapes $base. A returned
     * path is confined but not guaranteed to exist — the caller reads it and treats a read failure as
     * "absent", distinct from this method's null which means "rejected escape".
     */
    public static function resolve(string $base, string $relative): ?string
    {
        $base = self::normalize($base);
        $candidate = self::normalize($base.'/'.ltrim($relative, '/'));

        if (! self::within($base, $candidate)) {
            return null;
        }

        // Defend against symlink escapes: if the target (or its parent) exists, realpath must still
        // land inside the realpath of the base.
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
     * Collapse `.` and `..` segments lexically, preserving a leading `/`. Public so the adapter's
     * base-path relativisation — the inverse direction — shares this one normalizer rather than
     * re-rolling it.
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
