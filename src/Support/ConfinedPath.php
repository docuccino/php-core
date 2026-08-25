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
     * free to drift from the rule exactly as a copy of the guard would be. The `FILE_*` pair addresses
     * a `file:` attribute argument and the `CONFIG_FILE_*` pair a configured path, which is the one
     * thing that differs between them: where the author goes to change it.
     */
    public const string FILE_ESCAPED_HELP = 'Point `file:` at a path inside the application, written relative to its root.';

    public const string FILE_MISSING_HELP = 'Create the file, or correct the path — it is read relative to the application root.';

    /**
     * The same two outcomes for a CONFIGURED path rather than an attribute argument. Same rule, same
     * remedy, different thing to go and edit — and an author sent to look for a `file:` argument that
     * is really a config key spends their time in the wrong file.
     */
    public const string CONFIG_FILE_ESCAPED_HELP = 'Point the configured path inside the application, written relative to its root.';

    public const string CONFIG_FILE_MISSING_HELP = 'Create the file, or correct the configured path — it is read relative to the application root.';

    /**
     * The absolute path $relative resolves to under $base, or null when it is refused. A returned path
     * is confined but may not exist: callers treat a read failure as "absent", which is a different
     * thing from this null, meaning "refused — this names no path inside the application".
     */
    public static function resolve(string $base, string $relative): ?string
    {
        // A NUL byte is neither a traversal nor an absent file: it is a path no filesystem can hold
        // ({@see holdable()}). PHP lets an author write one by accident, a stray escape in a
        // double-quoted attribute argument, so it is refused HERE. Refusing it at a reporter would
        // leave every other caller of a security control crashing, and the nearest catch is
        // per-route: one stray escape would cost the author the whole route rather than one example.
        if (self::holdable($base) === null || self::holdable($relative) === null) {
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
        return self::holdable($configured) === null
            ? null
            : (str_starts_with($configured, '/') ? $configured : self::resolve($base, $configured));
    }

    /**
     * $path, or null when no filesystem call could accept it — the NUL refusal above, for the paths
     * this class does NOT confine.
     *
     * Confinement and holdability are different questions and only one of them has exceptions: an
     * overlay glob, an export destination and a fragment-cache directory may legitimately point outside
     * the application, so they never come through {@see resolve()} — and a NUL byte raises a `ValueError`
     * out of `glob()`, `realpath()`, `file_get_contents()`, `scandir()` and `mkdir()` all the same, with
     * `@` no help because it is a throw and not a warning. So the refusal belongs to every configured
     * path and not only to the confined ones, and it is stated once, here, rather than at each reader.
     */
    public static function holdable(string $path): ?string
    {
        return str_contains($path, "\0") ? null : $path;
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
