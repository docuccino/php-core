<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Confines a user-supplied relative path to a base directory. `#[Description(file: …)]` and
 * `info.description.file` both read a project file whose path comes from config or an attribute, so
 * `../../etc/passwd` must never escape the app. Resolution is lexical first — collapsing `.` / `..`
 * without touching the filesystem, which catches traversal even for targets that don't exist — then
 * re-checked through {@see realpath()} when the target does exist, so symlinks can't tunnel out.
 *
 * A refusal is a return value, never an exception. A security control whose failure mode is a throw
 * costs its caller more than the thing it was guarding, so anything the filesystem functions would
 * raise on is refused before they see it.
 *
 * @internal
 */
final class ConfinedPath
{
    /**
     * What to tell an author whose `file:` was refused, and what to tell one whose confined path held
     * no readable file — the two outcomes {@see resolve()} distinguishes, and so the two sentences
     * this class owns. They are stated here rather than at each reporter because a remedy that no
     * longer matches the rule sends the author to fix something that was never wrong, and a copy is
     * free to drift from the rule exactly as a copy of the guard would be. The wording addresses a
     * `file:` attribute argument, which is what every site reporting these outcomes today is reading;
     * a configured path refused the same way wants a sentence of its own.
     */
    public const string FILE_ESCAPED_HELP = 'Point `file:` at a path inside the application, written relative to its root.';

    public const string FILE_MISSING_HELP = 'Create the file, or correct the path — it is read relative to the application root.';

    /**
     * The absolute path $relative resolves to under $base, or null when it is refused. A returned path
     * is confined but may not exist: callers treat a read failure as "absent", which is a different
     * thing from this null, meaning "refused — this names no path inside the application".
     */
    public static function resolve(string $base, string $relative): ?string
    {
        // A NUL byte is neither a traversal nor an absent file: it is a path no filesystem can hold,
        // and every function that would answer for one — realpath(), file_get_contents(), is_dir() —
        // raises a ValueError instead. PHP lets an author write one by accident, a stray escape in a
        // double-quoted attribute argument, so it is refused HERE. Refusing it at a reporter would
        // leave every other caller of a security control crashing, and the nearest catch is
        // per-route: one stray escape would cost the author the whole route rather than one example.
        if (str_contains($base, "\0") || str_contains($relative, "\0")) {
            return null;
        }

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

    /**
     * A configured directory resolved for reading: an absolute path is taken verbatim, because naming
     * one is a deliberate statement about where the machine keeps something, and a relative one is
     * confined to $base. Null carries the same meaning as {@see resolve()}'s — refused.
     */
    public static function configuredDir(string $base, string $configured): ?string
    {
        if (str_contains($configured, "\0")) {
            return null;
        }

        return str_starts_with($configured, '/') ? $configured : self::resolve($base, $configured);
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
