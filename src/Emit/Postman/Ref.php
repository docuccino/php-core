<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Contract\Refs;

/**
 * `$ref` following for the collection emitter: {@see Refs}, the one resolver, reached one way.
 *
 * **In a collection, the components map IS the document root.** Every pointer that can stand at a
 * position this emitter reads is `#/components/…`, so the map is handed to `Refs` as the root it
 * resolves against — and a chain, a cycle and an escaped pointer read here exactly as they read in the
 * contract half.
 *
 * A reference landing nowhere comes back as the `$ref` node it stood on, together with the reference
 * that went nowhere. That node says nothing about the shape behind it — no `content`, no `headers`, no
 * `schema`, no `name` — so a caller degrades on it, reading the third element wherever the degraded
 * answer would otherwise be indistinguishable from a shape somebody wrote.
 *
 * @internal
 */
final class Ref
{
    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $components
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}
     */
    public static function follow(array $node, array $components): array
    {
        return Refs::follow(['components' => $components], $node, []);
    }
}
