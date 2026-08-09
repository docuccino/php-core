<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * Maps a raw operation tag — from `#[Group]` or an integration — to its display tag. Per-document
 * config picks the implementation: the adapter's `PrefixTagMapper` matches by exact key then prefix, or
 * a class-string in `tags.mapper` swaps in your own. Must be a pure function of the tag, or output
 * stops being deterministic.
 */
interface TagMapper
{
    public function map(string $tag): string;
}
