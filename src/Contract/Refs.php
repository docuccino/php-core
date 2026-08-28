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
     * The node a `$ref` chain ends at, with the pointer segments that address it, and the reference
     * that went nowhere — null where everything resolved, or where there was no `$ref` at all.
     *
     * A reference that goes nowhere still degrades to the `$ref` node itself, so a caller mid-walk can
     * carry on; but a node that is only a pointer says nothing about `required`, `schema` or anything
     * else, and a caller that read those off it would be checking a header against nothing and calling
     * it a pass. So non-resolution is REPORTED rather than implied, and the callers that answer for a
     * document turn it into a violation: a `$ref` at a name the document does not define is a broken
     * document, not an uncheckable one.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}
     */
    public static function follow(array $document, array $node, array $segments): array
    {
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $ref = $node['$ref'] ?? null;

            if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
                return [$node, $segments, null];
            }

            $target = self::segments($ref);
            $resolved = $document;

            foreach ($target as $segment) {
                if (! is_array($resolved) || ! array_key_exists($segment, $resolved)) {
                    return [$node, $segments, $ref];
                }
                $resolved = $resolved[$segment];
            }

            if (! is_array($resolved)) {
                return [$node, $segments, $ref];
            }

            /** @var array<string, mixed> $resolved */
            $node = $resolved;
            $segments = $target;
        }

        // Out of hops with a `$ref` still in hand: the chain never lands, which is a cycle.
        $last = $node['$ref'] ?? null;

        return [$node, $segments, is_string($last) ? $last : null];
    }

    /**
     * One member of an OAS object with its `$ref` chain followed, or null when the member is not there
     * (or is not an object). The one reader for "the requestBody of this thing", whatever the thing is.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments  pointer segments addressing $node
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}|null
     */
    public static function member(array $document, array $node, string $member, array $segments): ?array
    {
        $value = $node[$member] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return self::follow($document, $value, [...$segments, $member]);
    }

    /**
     * Whether the member is THERE and is not an object — a `requestBody` written as a string, say.
     *
     * {@see member()} answers null to that and to "no such member" alike, and the two want different
     * answers: nothing written is a document with nothing to say, and a caller passes in silence
     * because there is no promise to be held to. Something written that this cannot read is a promise
     * nobody checked, which is a note ({@see ContractChecker}). Asked here rather than at the caller,
     * so the member is read as an object by one grammar and not two.
     *
     * @param  array<string, mixed>  $node
     */
    public static function malformed(array $node, string $member): bool
    {
        return array_key_exists($member, $node) && ! is_array($node[$member]);
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
