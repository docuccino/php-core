<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

/**
 * The mutable bag behind {@see RouteContext::dependencies()}: the files behind facts an extension read
 * out-of-band — a `#[DescriptionFromFile]` markdown file, a separately analysed FormRequest, a traced
 * helper — each of which joins the fragment cache key's dependency manifest.
 *
 * Scalar (non-file) cache inputs go on {@see RouteDescriptor::$cacheInputs} instead: those have to fold
 * into the pre-build lookup key, whereas files are validated afterwards by re-hashing.
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
