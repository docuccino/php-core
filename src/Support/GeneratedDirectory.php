<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Creates a directory Docuccino generates for its own machine output (the fragment cache, the
 * analyser's scratch dir) and makes it self-ignoring: a `.gitignore` of `*` + `!.gitignore`, the
 * trick Laravel ships inside every generated `storage/` directory. Without it a cache under the
 * app tree turns up untracked in `git status` and gets committed by the thousand.
 *
 * Only ever for machine output — the exported spec is a reviewable artifact meant to be committed
 * and never comes through here. Everything is best-effort and quiet: an unwritable location leaves
 * the caller to fail (or not) on its own write, and a `.gitignore` already there is never rewritten,
 * so a user's edits survive.
 *
 * @internal
 */
final class GeneratedDirectory
{
    private const string GITIGNORE = "*\n!.gitignore\n";

    public static function ensure(string $path): void
    {
        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        $gitignore = rtrim($path, '/').'/.gitignore';
        if (! file_exists($gitignore)) {
            @file_put_contents($gitignore, self::GITIGNORE);
        }
    }
}
