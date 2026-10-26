<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One document's local `$ref`s, each followed once however many nodes use it: a chain read afresh at every
 * use costs each hop's length at each use, which the document's size does not bound. Where each reference
 * lands — the document's own node, its segments and, once asked, its pointer — is remembered by the
 * reference's text, so the memo grows with the references the document writes and never with their uses,
 * and lives as long as the walk that holds it.
 *
 * @internal
 */
final class RefHops
{
    /** A chain this long is a cycle in the document, not a document a reader meant to write. */
    private const int MAX_HOPS = 8;

    /**
     * Where each local reference lands — the node, and the segments addressing it — or false where it lands
     * on nothing.
     *
     * @var array<string, array{array<string, mixed>, list<string>}|false>
     */
    private array $landings = [];

    /**
     * The pointer to where each reference lands, by the reference.
     *
     * @var array<string, string>
     */
    private array $pointers = [];

    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(private readonly array $document) {}

    /**
     * The node a `$ref` chain from $node ends at, with the segments that address it, and the reference that
     * went nowhere: {@see Refs::follow()}, against this document.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments  pointer segments addressing $node
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}
     */
    public function follow(array $node, array $segments): array
    {
        [$node, $landed, , $dangling] = $this->chase($node);

        return [$node, $landed ?? $segments, $dangling];
    }

    /**
     * {@see follow()}, with where the chain ends spelt as a pointer: once for each reference, however many
     * nodes it is followed from.
     *
     * @param  array<string, mixed>  $node
     * @param  string  $at  the pointer to $node
     * @return array{0: array<string, mixed>, 1: string, 2: string|null}
     */
    public function followTo(array $node, string $at): array
    {
        [$node, $landed, $last, $dangling] = $this->chase($node);

        return [$node, $landed === null || $last === null ? $at : ($this->pointers[$last] ??= Pointer::of($landed)), $dangling];
    }

    /**
     * The node a chain from $node ends at; the segments addressing it and the reference that landed there,
     * both null where the chain never left $node; and the reference that went nowhere, null where everything
     * resolved.
     *
     * @param  array<string, mixed>  $node
     * @return array{array<string, mixed>, list<string>|null, string|null, string|null}
     */
    private function chase(array $node): array
    {
        $landed = null;
        $last = null;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $ref = $node['$ref'] ?? null;

            if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
                return [$node, $landed, $last, null];
            }

            $landing = $this->landings[$ref] ??= $this->land($ref);

            if ($landing === false) {
                return [$node, $landed, $last, $ref];
            }

            [$node, $landed] = $landing;
            $last = $ref;
        }

        // Out of hops with a `$ref` still in hand: the chain never lands, which is a cycle.
        $ref = $node['$ref'] ?? null;

        return [$node, $landed, $last, is_string($ref) ? $ref : null];
    }

    /**
     * Where the local pointer $ref lands, read off the document, or false where any step is missing or it
     * names no object.
     *
     * @return array{array<string, mixed>, list<string>}|false
     */
    private function land(string $ref): array|false
    {
        $segments = array_map(Pointer::unescape(...), explode('/', substr($ref, 2)));
        $resolved = $this->document;

        foreach ($segments as $segment) {
            if (! is_array($resolved) || ! array_key_exists($segment, $resolved)) {
                return false;
            }
            $resolved = $resolved[$segment];
        }

        if (! is_array($resolved)) {
            return false;
        }

        /** @var array<string, mixed> $resolved */
        return [$resolved, $segments];
    }
}
