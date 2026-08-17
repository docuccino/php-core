<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * Keys the two sides of a diff by the identity each node carries, falling back to a structural key where
 * there is no identity to key on.
 *
 * An id is read off an artifact nobody validated, so nothing stops two nodes claiming the same one — and
 * an id and a structural key are two names in ONE key space, so nothing stops one node's id spelling
 * another's structural key either. Keyed naively the second node overwrites the first and the node it hid
 * disappears from the comparison — a removal reported as no change, which is the one answer a diff must
 * never give. So a key claimed more than once on either side is qualified on BOTH sides, and qualified
 * again if that still collides: first with the node's structural key, then with a fingerprint of its
 * content. The nodes that do correspond still meet, and the extra one reads as the add or the remove it
 * is. A key claimed once is left alone.
 *
 * Qualifying has to escape that shared key space rather than write further into it, or the last qualifier
 * hands a node the key another node already holds and hides it exactly as the first collision did — with
 * nothing left to re-check it against. So a qualified key states its parts length-prefixed, tagged with
 * the kind of node and the level that minted it, behind a run of `#` no unqualified key starts with
 * ({@see mark()}): two qualifications spell one string only when every part agrees, and no qualification
 * can spell a key that was left alone.
 *
 * Every qualifier is a function of the node, never of the order it was met, so reordering one side invents
 * no churn. Nodes that agree on identity, structure and content are one node stated twice, and share a key.
 *
 * @internal
 */
final class IdentityKeys
{
    /** How many times a contested key may be qualified before the nodes claiming it are indistinguishable. */
    private const int QUALIFIERS = 2;

    /**
     * @template T
     *
     * @param  list<array{0: ?string, 1: string, 2: string, 3: T}>  $old  identity (null when not pairing by identity), structural key, content fingerprint, node
     * @param  list<array{0: ?string, 1: string, 2: string, 3: T}>  $new
     * @return array{array<string, T>, array<string, T>}
     */
    public static function pair(array $old, array $new): array
    {
        $oldKeys = array_map(self::baseKey(...), $old);
        $newKeys = array_map(self::baseKey(...), $new);

        $mark = self::mark([...$oldKeys, ...$newKeys]);

        for ($level = 1; $level <= self::QUALIFIERS; $level++) {
            $contested = self::claimedTwice($oldKeys) + self::claimedTwice($newKeys);

            if ($contested === []) {
                break;
            }

            $oldKeys = self::requalified($old, $oldKeys, $contested, $level, $mark);
            $newKeys = self::requalified($new, $newKeys, $contested, $level, $mark);
        }

        return [self::keyed($old, $oldKeys), self::keyed($new, $newKeys)];
    }

    /**
     * The name a node claims outright: its id, or its structure where it carries none.
     *
     * @param  array{0: ?string, 1: string, 2: string, 3: mixed}  $entry
     */
    private static function baseKey(array $entry): string
    {
        return $entry[0] ?? $entry[1];
    }

    /**
     * A node with no identity is already keyed by its structure, so it has one qualifier where a node
     * carrying one has two — and a level that adds no new part still moves it off the key it collided on,
     * because the level is in the tag.
     *
     * @param  array{0: ?string, 1: string, 2: string, 3: mixed}  $entry
     */
    private static function qualifiedKey(array $entry, int $level, string $mark): string
    {
        [$id, $structural, $fingerprint] = $entry;

        $parts = match (true) {
            $id === null => [$structural, $fingerprint],
            $level === 1 => [$id, $structural],
            default => [$id, $structural, $fingerprint],
        };

        // `s` or `i` — keyed by structure or by an identity. The two carry different parts, so without the
        // tag one node's structure-and-fingerprint could spell another's id-and-structure.
        $key = $mark.($id === null ? 's' : 'i').$level;

        foreach ($parts as $part) {
            $key .= '#'.strlen($part).'#'.$part;
        }

        return $key;
    }

    /**
     * The shortest run of `#` no key a node claims outright begins with. Read off the whole set, so it is a
     * function of what is being paired rather than of the order any of it arrived in.
     *
     * @param  list<string>  $keys
     */
    private static function mark(array $keys): string
    {
        $longest = 0;

        foreach ($keys as $key) {
            $longest = max($longest, strspn($key, '#'));
        }

        return str_repeat('#', $longest + 1);
    }

    /**
     * @param  list<array{0: ?string, 1: string, 2: string, 3: mixed}>  $entries
     * @param  list<string>  $keys
     * @param  array<string, true>  $contested
     * @return list<string>
     */
    private static function requalified(array $entries, array $keys, array $contested, int $level, string $mark): array
    {
        $out = [];

        foreach ($keys as $index => $key) {
            $out[] = isset($contested[$key]) ? self::qualifiedKey($entries[$index], $level, $mark) : $key;
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private static function claimedTwice(array $keys): array
    {
        $seen = [];
        $twice = [];

        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                $twice[$key] = true;
            }

            $seen[$key] = true;
        }

        return $twice;
    }

    /**
     * @template T
     *
     * @param  list<array{0: ?string, 1: string, 2: string, 3: T}>  $entries
     * @param  list<string>  $keys
     * @return array<string, T>
     */
    private static function keyed(array $entries, array $keys): array
    {
        $out = [];

        foreach ($entries as $index => $entry) {
            $out[$keys[$index]] = $entry[3];
        }

        return $out;
    }
}
