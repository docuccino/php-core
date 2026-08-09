<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

/**
 * The full set of compiled pages for one document, before directive resolution and nav assembly.
 * Carries a deterministic {@see digest()} of the content tree (source paths + per-file content
 * hashes) so a content-file edit invalidates the document-level fragment-cache key (design §10, A2).
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
     * A file-order-independent fingerprint of the content tree: every source path paired with its
     * content hash, sorted, then hashed. Two builds over the same files (in any read order) produce
     * the same digest; any edit, addition or removal changes it.
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
