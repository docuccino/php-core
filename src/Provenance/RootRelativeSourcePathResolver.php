<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * Turns an absolute source path into a stable, project-root-relative one for provenance
 * `source.file` — provenance only, never identity. Files under the supplied base path relativise
 * against it (`app/Http/Controllers/…`). Files outside it — a testbench workbench, path-repo
 * packages — have no base to strip, so we walk up to the nearest `composer.json` ancestor and
 * relativise against that package root. Either way, no absolute machine-specific path escapes.
 *
 * The composer-ancestor walk is framework-neutral, so it lives in core; each adapter constructs it
 * with its own base path (the Laravel one binds `base_path()`).
 */
final readonly class RootRelativeSourcePathResolver implements SourcePathResolver
{
    public function __construct(
        private string $basePath,
    ) {}

    public function relative(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);

        $base = rtrim(str_replace('\\', '/', $this->basePath), '/');
        if ($base !== '' && str_starts_with($normalized, $base.'/')) {
            return substr($normalized, strlen($base) + 1);
        }

        $root = $this->composerRoot($normalized);
        if ($root !== null && str_starts_with($normalized, $root.'/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return $normalized;
    }

    /**
     * Nearest ancestor holding a `composer.json`, or null if we hit the filesystem root first.
     */
    private function composerRoot(string $file): ?string
    {
        $dir = dirname($file);

        while ($dir !== '' && $dir !== '.' && $dir !== '/') {
            if (is_file($dir.'/composer.json')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }
}
