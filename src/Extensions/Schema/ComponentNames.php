<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

/**
 * Decides the name every class-identified component schema is published under, and rewrites the
 * `$ref`s that point at them.
 *
 * Registration hands out names first-come, which is only ever provisional: the plain name would go to
 * whichever route happened to sort first, so adding an unrelated route that sorts earlier could swap
 * two components' names without changing a byte of either shape — a silent breaking change for every
 * generated client. So the names two classes contesting one name are published under are derived from
 * their FQCNs instead: walk up the namespaces until the names differ, and retire the contested plain
 * name rather than awarding it to one of them. `App\Data\SSO\SSOConnectionData` and
 * `App\Schema\Auth\SSOConnectionData` become `SSOSSOConnectionData` and `AuthSSOConnectionData`.
 *
 * Every name this produces is a function of the contesting FQCNs alone — never of discovery,
 * registration or route order — which is what makes it stable across builds.
 *
 * @internal
 */
final class ComponentNames
{
    /** The head of every component reference; the bucket and name follow it. */
    private const PREFIX = '#/components/';

    /**
     * The published name of every schema whose registration name isn't the one it should keep:
     * registration name → published name. Names absent from the result keep the name they have.
     *
     * @param  array<string, string>  $schemaIds  registration name → the identity it was hoisted for
     * @param  list<string>  $names  every registered schema name, identified or not
     * @return array<string, string>
     */
    public static function resolve(array $schemaIds, array $names): array
    {
        [$contested, $reserved] = self::partition($schemaIds, $names);

        if ($contested === []) {
            return [];
        }

        return self::assign($contested, self::depths($contested, $reserved), $reserved);
    }

    /**
     * Every base name more than one schema claimed, as contested name → published name → identity.
     * Includes the contests nothing renamed — a pair whose identities have no namespace between them
     * still ended up on `Foo` and `Foo_2`, and the author still deserves to hear about it.
     *
     * @param  array<string, string>  $schemaIds
     * @param  list<string>  $names
     * @return array<string, array<string, string>>
     */
    public static function contests(array $schemaIds, array $names): array
    {
        $renames = self::resolve($schemaIds, $names);
        $taken = array_fill_keys($names, true);

        $out = [];
        foreach (self::groups($names, $taken) as $base => $group) {
            if (count($group) < 2) {
                continue;
            }

            foreach ($group as $name) {
                $out[(string) $base][$renames[$name] ?? $name] = $schemaIds[$name] ?? 'an unidentified schema';
            }
        }

        return $out;
    }

    /**
     * Split the registered names into the ones a namespace walk can separate and the ones it must
     * leave alone. A group only qualifies when every claimant carries a namespaced identity AND those
     * identities are distinct CLASSES: one class hoisted twice — a request shape beside its response
     * shape — shares every namespace segment it has, so walking them would only trade `Foo_2` for a
     * longer name that still needs a suffix.
     *
     * @param  array<string, string>  $schemaIds
     * @param  list<string>  $names
     * @return array{array<string, array{base: string, fqcn: string}>, array<string, true>}
     */
    private static function partition(array $schemaIds, array $names): array
    {
        $taken = array_fill_keys($names, true);

        /** @var array<string, true> $reserved names nothing is going to rename, so nothing may take */
        $reserved = [];
        /** @var array<string, array{base: string, fqcn: string}> $contested */
        $contested = [];

        foreach (self::groups($names, $taken) as $base => $group) {
            $classes = [];
            foreach ($group as $name) {
                $class = isset($schemaIds[$name]) ? self::classIdentity($schemaIds[$name]) : null;
                if ($class !== null && str_contains($class, '\\')) {
                    $classes[$class] = true;
                }
            }

            $separable = count($group) > 1 && count($classes) === count($group);

            foreach ($group as $name) {
                if ($separable) {
                    $contested[$name] = ['base' => (string) $base, 'fqcn' => $schemaIds[$name]];
                } else {
                    $reserved[$name] = true;
                }
            }
        }

        return [$contested, $reserved];
    }

    /**
     * The registered names grouped under the base each asked for. `Foo_2` only exists because `Foo`
     * was taken, so the base is recoverable from the finished name set — no need to track which
     * registration wanted what, which a warm cache hit could not replay anyway.
     *
     * @param  list<string>  $names
     * @param  array<string, true>  $taken
     * @return array<string, list<string>>
     */
    private static function groups(array $names, array $taken): array
    {
        $groups = [];
        foreach ($names as $name) {
            $groups[self::base($name, $taken)][] = $name;
        }

        return $groups;
    }

    /** The class half of a schema identity — `App\Data\User#request` is still `App\Data\User`. */
    private static function classIdentity(string $schemaId): string
    {
        $hash = strpos($schemaId, '#');

        return $hash === false ? $schemaId : substr($schemaId, 0, $hash);
    }

    /**
     * How many namespace segments each contested name needs to stand apart — from every other
     * contested name and from every name nothing is renaming. Deepening one group can push it onto a
     * name someone else proposed, so this runs to a fixed point; depth only ever rises and each FQCN
     * has finitely many segments, so it terminates.
     *
     * @param  array<string, array{base: string, fqcn: string}>  $contested
     * @param  array<string, true>  $reserved
     * @return array<string, int>
     */
    private static function depths(array $contested, array $reserved): array
    {
        $depth = array_map(static fn (): int => 1, $contested);

        while (true) {
            $deepened = false;

            foreach (self::byProposal($contested, $depth) as $proposal => $claimants) {
                if (count($claimants) === 1 && ! isset($reserved[$proposal])) {
                    continue;
                }

                foreach ($claimants as $name) {
                    if ($depth[$name] < count(self::segments($contested[$name]['fqcn']))) {
                        $depth[$name]++;
                        $deepened = true;
                    }
                }
            }

            if (! $deepened) {
                return $depth;
            }
        }
    }

    /**
     * The final name for each contested registration. Anything still sharing a proposal has run out
     * of namespace to walk — identical namespaces under different roots, or two FQCNs that sanitize
     * to one name — so it falls back to a numeric suffix ordered by FQCN, which is still a function of
     * the contesting set rather than of who registered first.
     *
     * @param  array<string, array{base: string, fqcn: string}>  $contested
     * @param  array<string, int>  $depth
     * @param  array<string, true>  $reserved
     * @return array<string, string>
     */
    private static function assign(array $contested, array $depth, array $reserved): array
    {
        $used = $reserved;
        $renames = [];

        $byProposal = self::byProposal($contested, $depth);
        ksort($byProposal);

        foreach ($byProposal as $proposal => $claimants) {
            usort($claimants, static fn (string $a, string $b): int => $contested[$a]['fqcn'] <=> $contested[$b]['fqcn']);

            foreach ($claimants as $name) {
                $candidate = (string) $proposal;
                for ($n = 2; isset($used[$candidate]); $n++) {
                    $candidate = $proposal.'_'.$n;
                }

                $used[$candidate] = true;
                if ($candidate !== $name) {
                    $renames[$name] = $candidate;
                }
            }
        }

        return $renames;
    }

    /**
     * @param  array<string, array{base: string, fqcn: string}>  $contested
     * @param  array<string, int>  $depth
     * @return array<string, list<string>>
     */
    private static function byProposal(array $contested, array $depth): array
    {
        $out = [];
        foreach ($contested as $name => $entry) {
            $out[self::qualify($entry['base'], $entry['fqcn'], $depth[$name])][] = $name;
        }

        return $out;
    }

    /** The base name prefixed with the innermost `$depth` namespace segments of its class. */
    private static function qualify(string $base, string $fqcn, int $depth): string
    {
        $segments = self::segments($fqcn);

        return self::sanitize(implode('', array_slice($segments, -$depth)).$base);
    }

    /**
     * The namespace segments of an FQCN, outermost first — the class's own short name excluded.
     *
     * @return list<string>
     */
    private static function segments(string $fqcn): array
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        array_pop($parts);

        return $parts;
    }

    /**
     * The name a registration asked for, recovered from the name it got: strip the disambiguating
     * suffix while what's left is itself a registered name.
     *
     * @param  array<string, true>  $taken
     */
    private static function base(string $name, array $taken): string
    {
        while (preg_match('/^(.+)_\d+$/', $name, $matches) === 1 && isset($taken[$matches[1]])) {
            $name = $matches[1];
        }

        return $name;
    }

    /** Reduce a name to the characters a `$ref` may carry, never to nothing. */
    public static function sanitize(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '', $name);
        $clean = is_string($clean) ? $clean : '';

        return $clean === '' ? 'Schema' : $clean;
    }

    /**
     * Rewrite every `#/components/{$kind}/…` reference under `$node` through a rename map.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $node
     * @param  array<string, string>  $renames
     * @return array<TKey, mixed>
     */
    public static function rename(array $node, array $renames, string $kind = 'schemas'): array
    {
        if ($renames === []) {
            return $node;
        }

        $prefix = self::PREFIX.$kind.'/';

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, $prefix)) {
                $renamed = $renames[substr($value, strlen($prefix))] ?? null;
                if ($renamed !== null) {
                    $node[$key] = $prefix.$renamed;
                }

                continue;
            }

            if (is_array($value)) {
                $node[$key] = self::rename($value, $renames, $kind);
            }
        }

        return $node;
    }

    /**
     * Rekey a component bucket through a rename map.
     *
     * @template TBody
     *
     * @param  array<string, TBody>  $bucket
     * @param  array<string, string>  $renames
     * @return array<string, TBody>
     */
    public static function rekey(array $bucket, array $renames): array
    {
        if ($renames === []) {
            return $bucket;
        }

        $out = [];
        foreach ($bucket as $name => $value) {
            $out[$renames[(string) $name] ?? (string) $name] = $value;
        }

        return $out;
    }
}
