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
     * Each call reads the chain afresh: a walk following one document's references many times holds a
     * {@see RefHops} over it instead, which reads each once.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}
     */
    public static function follow(array $document, array $node, array $segments): array
    {
        return (new RefHops($document))->follow($node, $segments);
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
}
