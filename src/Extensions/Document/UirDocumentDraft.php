<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Document;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Support\Arr;

/**
 * A mutable, array-shaped view of the assembled document, handed to {@see DocumentTransformer}s and
 * overlay application before canonicalisation. Raw arrays rather than the immutable
 * {@see UirDocument} so transformers and overlays can touch any node uniformly.
 */
final class UirDocumentDraft
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        private array $document,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->document;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function replace(array $document): void
    {
        $this->document = $document;
    }

    public function set(string $key, mixed $value): void
    {
        $this->document[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->document[$key] ?? null;
    }

    /**
     * The value at a concrete key-path, or null when any segment is missing.
     *
     * @param  list<int|string>  $path
     */
    public function at(array $path): mixed
    {
        return Arr::valueAt($this->document, $path);
    }
}
