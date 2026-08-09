<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * Turns an absolute source file path into a stable, project-root-relative one for provenance
 * `source.file` (design §4 — provenance, never identity). A file under the supplied base path
 * relativises against it (the common case: `app/Http/Controllers/…`). A file *outside* base path —
 * a testbench workbench, path-repo packages — has no base to strip, so we walk up to the nearest
 * `composer.json` ancestor and relativise against that package root, keeping the path portable
 * regardless of where the repository is checked out (never an absolute, machine-specific path).
 *
 * The composer-ancestor walk is a pure, framework-neutral algorithm, so it lives in core: any
 * adapter constructs it with its own project base path (the Laravel adapter binds `base_path()`).
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
     * The nearest ancestor directory that contains a `composer.json`, or null if none is found
     * before reaching the filesystem root.
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
