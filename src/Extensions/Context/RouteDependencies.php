<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Schema\DeclarationFiles;

/**
 * The mutable bag behind {@see RouteContext::dependencies()}: the files behind facts an extension read
 * out-of-band — a `#[Description(file: …)]` markdown file, a separately analysed FormRequest, a traced
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

    /** Whether this fragment may be stored at all — see {@see refuseCaching()}. */
    private bool $cacheable = true;

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

    /**
     * Refuse to store this route's fragment at all, for a reader that found an output-shaping input the
     * manifest cannot express — a collaborator whose answer no file holds ({@see DeclarationFiles::keyableFor()}).
     *
     * The manifest has no way to say "never fresh": an absent file compares fresh while it stays absent,
     * so a route keyed on nothing looks keyed. Refusing costs that route one rebuild per build; recording
     * less than its answer depends on costs the document.
     */
    public function refuseCaching(): void
    {
        $this->cacheable = false;
    }

    /** Whether a reader refused this fragment the cache. The pipeline builds it either way. */
    public function cachingRefused(): bool
    {
        return ! $this->cacheable;
    }
}
