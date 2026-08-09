<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * Maps a raw operation tag (as produced by `#[Group]` or an integration) to its display tag
 * (design §Multiple documents — tag mapping hooks). Per-document config picks the implementation;
 * the built-in adapter mapper (`Docuccino\Laravel\Tags\PrefixTagMapper`) maps by exact key then
 * prefix, and a custom class-string in `tags.mapper` swaps in a user implementation. Must be a pure
 * function of the tag so output stays deterministic.
 */
interface TagMapper
{
    public function map(string $tag): string;
}
