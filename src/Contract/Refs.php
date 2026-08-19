<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * Local `$ref` following for the OAS objects around a schema — responses, request bodies, parameters.
 * Schema `$ref`s are left alone: JSON Schema resolves those itself, and inlining one would break a
 * recursive model schema.
 *
 * @internal
 */
final class Refs
{
    /** A chain this long is a cycle in the document, not a document a reader meant to write. */
    private const int MAX_HOPS = 8;

    /**
     * The node a `$ref` chain ends at, with the pointer segments that address it. A reference that goes
     * nowhere resolves to itself, so the caller reports "nothing documented" rather than crashing.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function follow(array $document, array $node, array $segments): array
    {
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $ref = $node['$ref'] ?? null;

            if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
                return [$node, $segments];
            }

            $target = self::segments($ref);
            $resolved = $document;

            foreach ($target as $segment) {
                if (! is_array($resolved) || ! array_key_exists($segment, $resolved)) {
                    return [$node, $segments];
                }
                $resolved = $resolved[$segment];
            }

            if (! is_array($resolved)) {
                return [$node, $segments];
            }

            /** @var array<string, mixed> $resolved */
            $node = $resolved;
            $segments = $target;
        }

        return [$node, $segments];
    }

    /**
     * The unescaped segments of a local pointer.
     *
     * @return list<string>
     */
    private static function segments(string $ref): array
    {
        return array_map(Pointer::unescape(...), explode('/', substr($ref, 2)));
    }
}
