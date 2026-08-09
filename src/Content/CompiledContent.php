<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

/**
 * The compiled pages for one document, before directive resolution and nav assembly. Carries a
 * {@see digest()} of the content tree — source paths plus per-file content hashes — so editing a
 * content file invalidates the document-level fragment-cache key.
 *
 * @internal
 */
final readonly class CompiledContent
{
    /**
     * @param  list<CompiledPage>  $pages
     */
    public function __construct(public array $pages = []) {}

    public function isEmpty(): bool
    {
        return $this->pages === [];
    }

    /**
     * Every source path paired with its content hash, sorted, then hashed — so read order can't change
     * the digest, but any edit, addition or removal does.
     */
    public function digest(): string
    {
        if ($this->pages === []) {
            return '';
        }

        $rows = [];
        foreach ($this->pages as $page) {
            $rows[] = $page->sourceFile."\0".$page->sourceHash;
        }
        sort($rows);

        return hash('sha256', implode("\n", $rows));
    }
}
