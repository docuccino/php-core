<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\Hydrate;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads a document's `content.dir` markdown tree into a framework-neutral {@see CompiledContent}
 * (the filesystem-input half of the content pipeline — a second adapter or the reference CLI
 * compiles the identical tree; {@see ContentResolver} does the document-input half). Folders become default nav
 * groups; frontmatter (`title`, `slug`, `summary`, `tags`, and `nav.{group,order,hidden,type,ref}`)
 * overrides the derived values. Deterministic: files are read in sorted order and nothing time- or
 * machine-dependent enters a page (source paths are project-root-relative).
 *
 * A relative `content.dir` is confined to the app base path (security L2, like `#[DescriptionFromFile]`);
 * an absolute dir is trusted as-is (developer-authored config). A configured-but-missing directory is
 * a warning diagnostic; an unset or empty directory compiles to nothing (the document is unchanged —
 * no empty content key).
 *
 * @internal
 */
final readonly class ContentCompiler
{
    public function __construct(private string $basePath) {}

    /**
     * @return array{0: CompiledContent, 1: list<Diagnostic>}
     */
    public function compile(DocumentConfig $document): array
    {
        $configured = $document->contentDir();
        if ($configured === null) {
            return [new CompiledContent, []];
        }

        $dir = $this->resolveDir($configured);
        if ($dir === null) {
            return [new CompiledContent, [new Diagnostic(
                severity: Severity::Warning,
                code: 'content.dir-escapes-base',
                message: sprintf('The content directory "%s" escapes the application base path and was ignored.', $configured),
            )]];
        }

        if (! is_dir($dir)) {
            return [new CompiledContent, [new Diagnostic(
                severity: Severity::Warning,
                code: 'content.dir-missing',
                message: sprintf('The configured content directory "%s" does not exist.', $configured),
                help: 'Create it or unset documents.*.content.dir.',
            )]];
        }

        $prefix = $this->sourcePrefix($dir);

        $pages = [];
        foreach ($this->markdownFiles($dir) as $absolute) {
            $pages[] = $this->compilePage($absolute, $dir, $prefix);
        }

        return [new CompiledContent($pages), []];
    }

    /**
     * A relative dir is confined to the base path; an absolute dir is used verbatim. Null means a
     * confined relative path escaped the base.
     */
    private function resolveDir(string $configured): ?string
    {
        if (str_starts_with($configured, '/')) {
            return $configured;
        }

        return ConfinedPath::resolve($this->basePath, $configured);
    }

    private function compilePage(string $absolute, string $dir, string $prefix): CompiledPage
    {
        $raw = @file_get_contents($absolute);
        $raw = $raw === false ? '' : $raw;

        [$frontmatter, $body] = Frontmatter::parse($raw);
        $nav = Hydrate::map($frontmatter['nav'] ?? null);

        $relative = $this->relative($absolute, $dir);
        $slug = Hydrate::stringOrNull($frontmatter['slug'] ?? null) ?? $this->slugFromPath($relative);

        $navType = Hydrate::stringOrNull($nav['type'] ?? null);
        $navType = in_array($navType, ['page', 'operation', 'tag'], true) ? $navType : 'page';

        return new CompiledPage(
            slug: $slug,
            body: rtrim($body, "\n"),
            sourceFile: $prefix.$relative,
            sourceHash: hash('sha256', $raw),
            title: Hydrate::stringOrNull($frontmatter['title'] ?? null) ?? $this->humanize(basename($relative, '.md')),
            summary: Hydrate::stringOrNull($frontmatter['summary'] ?? null),
            order: Hydrate::intOrNull($nav['order'] ?? null),
            tags: Hydrate::stringList($frontmatter['tags'] ?? null),
            group: Hydrate::stringOrNull($nav['group'] ?? null) ?? $this->groupFromPath($relative),
            hidden: ($nav['hidden'] ?? false) === true,
            navType: $navType,
            navRef: Hydrate::stringOrNull($nav['ref'] ?? null),
        );
    }

    /**
     * Every `*.md` file under $dir, absolute paths, sorted so the read order never affects output.
     *
     * @return list<string>
     */
    private function markdownFiles(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** The project-root-relative prefix for a source path: the dir relative to base, or '' when outside it. */
    private function sourcePrefix(string $dir): string
    {
        $base = rtrim($this->basePath, '/').'/';

        return str_starts_with($dir, $base) ? substr($dir, strlen($base)).'/' : '';
    }

    private function relative(string $absolute, string $dir): string
    {
        $prefix = rtrim($dir, '/').'/';

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : basename($absolute);
    }

    /** The slug for a file: its dir-relative path without the `.md` extension. */
    private function slugFromPath(string $relative): string
    {
        return preg_replace('/\.md$/', '', $relative) ?? $relative;
    }

    /** The default group for a file: its immediate parent folder humanised, or null at the root. */
    private function groupFromPath(string $relative): ?string
    {
        $dir = dirname($relative);
        if ($dir === '.' || $dir === '') {
            return null;
        }

        return $this->humanize(basename($dir));
    }

    private function humanize(string $value): string
    {
        return ucwords(trim((string) preg_replace('/[-_]+/', ' ', $value)));
    }
}
