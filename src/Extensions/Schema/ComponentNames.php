<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Identity\Base32;

/**
 * Decides the name every component schema is published under, and rewrites the `$ref`s that point at
 * them.
 *
 * The name a registration lands on is a storage SLOT, handed out first-come — `Foo`, then `Foo_2` —
 * and first-come is route order. Published as-is, the plain name would go to whichever route happened
 * to sort first, so adding an unrelated route could swap what two components mean without changing a
 * byte of either shape: a silent breaking change for every generated client. So the published name is
 * derived from what a schema IS, never from the slot it got. Each registration states a CLAIM — the
 * name it asked for, the identity behind it, and the bytes it publishes — and proposes a name off
 * that:
 *
 * 1. the name it asked for, plus the facet of its identity: a class's request shape is not its
 *    response shape, so `App\Data\Article#request` proposes `ArticleRequest` and the class's own shape
 *    proposes `Article` — always, contested or not, so adding one never renames the other;
 * 2. then the innermost namespace segments of its identity, one at a time —
 *    `AuthenticationSSOConnectionData` beside `SSOSSOConnectionData`;
 * 3. then a prefix of the hash of its identity (its published bytes, for a schema that names no
 *    identity) — for two classes in one namespace, or a `#[SchemaId]` pin with no namespace to walk.
 *
 * While two claims propose the same name they both take their next rung, so nobody keeps a name two
 * claims asked for and every name is a function of the contesting set alone.
 *
 * @phpstan-type Claim array{base: string, identity: string|null, content: string}
 *
 * @internal
 */
final class ComponentNames
{
    /** The head of every component reference; the bucket and name follow it. */
    private const PREFIX = '#/components/';

    /** Characters of the identity hash a claim nothing else separates falls back to — 40 bits. */
    private const DISCRIMINATOR = 8;

    /**
     * The published name of every schema whose registration slot isn't the name it should keep:
     * registration name → published name. Names absent from the result keep the name they have.
     *
     * @param  array<string, Claim>  $claims  registration name → what it claims
     * @return array<string, string>
     */
    public static function resolve(array $claims): array
    {
        $renames = [];
        foreach (self::settle($claims)[0] as $name => $published) {
            if ($published !== (string) $name) {
                $renames[(string) $name] = $published;
            }
        }

        return $renames;
    }

    /**
     * Every name two or more schemas asked for, as contested name → published name → identity. The
     * facet rung is part of the ask, so a class's request shape beside its response shape is not a
     * contest — those two never wanted the same name in the first place.
     *
     * @param  array<string, Claim>  $claims
     * @return array<string, array<string, string>>
     */
    public static function contests(array $claims): array
    {
        [$names, $contested] = self::settle($claims);

        $out = [];
        foreach ($contested as $asked => $claimants) {
            foreach ($claimants as $name) {
                $out[$asked][$names[$name] ?? $name] = $claims[$name]['identity'] ?? 'an unidentified schema';
            }
        }

        return $out;
    }

    /**
     * The published name of every claim, plus the names two or more of them asked for.
     *
     * For a caller that MINTS names rather than renaming registration slots — the shared-error hoist,
     * which builds its components out of the finished document and has no registry to rename. It states
     * the same claims a registry does and gets the same answers, so the two paths cannot drift apart:
     * an identity-less claim's ladder is exactly the plain-name-then-content-hash pair such a caller
     * would otherwise hand-roll, and {@see deepen()} is already the rule that moves two equal claimants
     * off a name neither may keep.
     *
     * `$taken` names components this pass cannot move — ones already published by the time it runs. A
     * claim proposing one climbs past it, and the name counts as contested so the move is reported
     * rather than silent.
     *
     * @param  array<string, Claim>  $claims
     * @param  list<string>  $taken
     * @return array{array<string, string>, array<string, list<string>>}
     */
    public static function mint(array $claims, array $taken = []): array
    {
        return self::settle($claims, $taken);
    }

    /**
     * The published name of every claim, plus the claims that asked for a name someone else asked for.
     *
     * @param  array<string, Claim>  $claims
     * @param  list<string>  $taken
     * @return array{array<string, string>, array<string, list<string>>}
     */
    private static function settle(array $claims, array $taken = []): array
    {
        $ladders = array_map(self::ladder(...), $claims);
        $names = self::award($ladders, self::deepen($ladders, $taken), $claims, $taken);

        /** @var array<string, list<string>> $asked */
        $asked = [];
        foreach ($ladders as $name => $ladder) {
            $asked[$ladder[0]][] = (string) $name;
        }

        return [$names, array_filter(
            $asked,
            static fn (array $claimants, int|string $name): bool => count($claimants) > 1 || in_array((string) $name, $taken, true),
            ARRAY_FILTER_USE_BOTH,
        )];
    }

    /**
     * The names one claim will propose, in order: what it asked for, then each further namespace
     * segment of its identity, then the hash rung — which is unique per claim, so the ladder always
     * ends somewhere nobody else can be.
     *
     * @param  Claim  $claim
     * @return list<string>
     */
    private static function ladder(array $claim): array
    {
        $stem = self::sanitize($claim['base'].self::facet($claim['base'], $claim['identity']));

        $rungs = [$stem];

        $segments = self::segments($claim['identity'] ?? '');
        for ($depth = 1; $depth <= count($segments); $depth++) {
            $rungs[] = self::sanitize(implode('', array_slice($segments, -$depth)).$stem);
        }

        $rungs[] = $stem.'_'.self::discriminator($claim['identity'] ?? $claim['content']);

        return array_values(array_unique($rungs));
    }

    /**
     * How far up its ladder each claim has to climb to stand alone.
     *
     * Claims that asked for the same name equally are all moved off it — nobody keeps a name that
     * would have meant something else in a build that met the other one first. But a claim that climbs
     * ONTO a name someone else asked for plainly keeps climbing alone: the incumbent asked for it
     * without contest, and renaming it would let one part of an application move an unrelated one.
     *
     * A `$taken` name is that same incumbent, one that this pass cannot move at all — so everybody on
     * it climbs and it keeps what it has.
     *
     * Climbing lands claims on new names, so this runs to a fixed point; a rung only ever rises and
     * every ladder is finite, so it terminates.
     *
     * @param  array<string, list<string>>  $ladders
     * @param  list<string>  $taken
     * @return array<string, int>
     */
    private static function deepen(array $ladders, array $taken = []): array
    {
        $rungs = array_map(static fn (): int => 0, $ladders);

        while (true) {
            $climbed = false;

            /** @var array<string, list<string>> $proposals */
            $proposals = [];
            foreach ($ladders as $name => $ladder) {
                $proposals[$ladder[$rungs[$name]]][] = (string) $name;
            }

            foreach ($proposals as $proposal => $claimants) {
                if (in_array((string) $proposal, $taken, true)) {
                    foreach ($claimants as $name) {
                        if ($rungs[$name] < count($ladders[$name]) - 1) {
                            $rungs[$name]++;
                            $climbed = true;
                        }
                    }

                    continue;
                }

                if (count($claimants) < 2) {
                    continue;
                }

                $shallowest = min(array_map(static fn (string $name): int => $rungs[$name], $claimants));
                $tied = array_filter($claimants, static fn (string $name): bool => $rungs[$name] === $shallowest);

                foreach (count($tied) === count($claimants) ? $claimants : array_diff($claimants, $tied) as $name) {
                    if ($rungs[$name] < count($ladders[$name]) - 1) {
                        $rungs[$name]++;
                        $climbed = true;
                    }
                }
            }

            if (! $climbed) {
                return $rungs;
            }
        }
    }

    /**
     * The name each claim is published under. Two claims can only still share a proposal by sharing a
     * ladder's last rung, which takes two identities that hash alike — so the numeric tail here is a
     * guarantee that the map stays one-to-one rather than a naming strategy. Claims are awarded in
     * identity order, so even that tail is a function of the contesting set.
     *
     * @param  array<string, list<string>>  $ladders
     * @param  array<string, int>  $rungs
     * @param  array<string, Claim>  $claims
     * @param  list<string>  $taken
     * @return array<string, string>
     */
    private static function award(array $ladders, array $rungs, array $claims, array $taken = []): array
    {
        $order = array_map(strval(...), array_keys($ladders));
        usort($order, static fn (string $a, string $b): int => self::discriminant($claims[$a]) <=> self::discriminant($claims[$b]));

        $used = array_fill_keys($taken, true);
        $names = [];
        foreach ($order as $name) {
            $proposal = $ladders[$name][$rungs[$name]];

            $candidate = $proposal;
            for ($n = 2; isset($used[$candidate]); $n++) {
                $candidate = $proposal.'_'.$n;
            }

            $used[$candidate] = true;
            $names[$name] = $candidate;
        }

        // Awarded in identity order, handed back in registration order — the caller's map reads like
        // the registry it came from.
        $ordered = [];
        foreach ($ladders as $name => $ladder) {
            $ordered[(string) $name] = $names[(string) $name];
        }

        return $ordered;
    }

    /**
     * The qualifier an identity's facet contributes. `App\Data\Article#request` is the shape a client
     * SENDS, and calling it `Article` would leave the class's own shape to fight it for the name — one
     * of them losing to a suffix that says nothing. Empty when the name already says it, so a
     * `StoreWidgetRequest` never becomes `StoreWidgetRequestRequest`.
     */
    private static function facet(string $base, ?string $identity): string
    {
        if ($identity === null) {
            return '';
        }

        $hash = strpos($identity, '#');
        if ($hash === false) {
            return '';
        }

        $word = ucfirst(self::clean(substr($identity, $hash + 1)));

        return $word === '' || str_ends_with(strtolower($base), strtolower($word)) ? '' : $word;
    }

    /** @param  Claim  $claim */
    private static function discriminant(array $claim): string
    {
        return $claim['identity'] ?? $claim['content'];
    }

    /** A short hash of what makes a claim itself, in the same base32 alphabet as a node id. */
    private static function discriminator(string $source): string
    {
        return substr(Base32::encode(hash('sha256', $source, binary: true)), 0, self::DISCRIMINATOR);
    }

    /**
     * The namespace segments of an identity, outermost first — the class's own short name, and any
     * facet hanging off it, excluded. An identity with no namespace to walk yields none.
     *
     * @return list<string>
     */
    private static function segments(string $identity): array
    {
        $parts = explode('\\', trim($identity, '\\'));
        array_pop($parts);

        return $parts;
    }

    /** Reduce a name to the characters a `$ref` may carry, never to nothing. */
    public static function sanitize(string $name): string
    {
        $clean = self::clean($name);

        return $clean === '' ? 'Schema' : $clean;
    }

    /** The `$ref`-safe characters of a name, which may be none of them. */
    private static function clean(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '', $name);

        return is_string($clean) ? $clean : '';
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
