<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

/**
 * The per-route dependency-contribution seam (design §10): the mutable bag a {@see RouteContext}
 * exposes so extensions that read facts out-of-band — a `#[DescriptionFromFile]` markdown file, a
 * FormRequest analysed separately, a traced helper — can register the FILES those facts came from.
 * Every registered file joins the fragment cache key's dependency manifest, so editing any of them
 * invalidates the cached fragment.
 *
 * Scalar (non-file) cache inputs belong on {@see RouteDescriptor::$cacheInputs} instead: they must
 * fold into the pre-build lookup key, whereas files are validated after the fact by re-hashing.
 */
final class RouteDependencies
{
    /**
     * @var list<string>
     */
    private array $files = [];

    /** Register one dependency file (ignored when empty). */
    public function addFile(string $file): void
    {
        if ($file !== '') {
            $this->files[] = $file;
        }
    }

    /**
     * @param  list<string>  $files
     */
    public function addFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->addFile($file);
        }
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return $this->files;
    }
}
