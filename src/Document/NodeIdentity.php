<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

/**
 * A node's Docuccino id, in whichever form it survived. UIR carries it nested under `x-docuccino`; an
 * OpenAPI export has nowhere to put that object (it also carries provenance), so it projects the id
 * alone as a flat `x-docuccino-id`.
 *
 * Both forms name the same node, so every reader that pairs nodes owes both — operations, parameters,
 * responses and component schemas alike. Reading only the nested form puts an exported artifact and the
 * document it came from in disjoint key spaces.
 *
 * @internal
 */
final class NodeIdentity
{
    /** What an OpenAPI export writes in place of the nested `x-docuccino` object. */
    public const string FLAT_KEY = 'x-docuccino-id';

    /**
     * @param  array<string, mixed>  $rest  the node's non-modelled members, where a flat id lands
     */
    public static function of(?NodeExtension $nested, array $rest): ?string
    {
        $id = $nested?->id;

        if ($id !== null && $id !== '') {
            return $id;
        }

        return self::flat($rest);
    }

    /**
     * The same identity read off a raw node — schemas keep their keyword space verbatim rather than
     * modelling it.
     *
     * @param  array<string, mixed>  $node
     */
    public static function inArray(array $node): ?string
    {
        $nested = $node['x-docuccino'] ?? null;

        if (is_array($nested)) {
            $id = $nested['id'] ?? null;

            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return self::flat($node);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function flat(array $node): ?string
    {
        $flat = $node[self::FLAT_KEY] ?? null;

        return is_string($flat) && $flat !== '' ? $flat : null;
    }
}
