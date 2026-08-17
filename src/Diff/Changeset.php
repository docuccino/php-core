<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * The deterministic result of diffing two UIR documents. Changes are sorted by
 * {@see Change::sortKey()} so `toArray()` is stable across runs.
 */
final readonly class Changeset
{
    /**
     * @param  list<Change>  $changes  already sorted by the caller ({@see DocumentDiffer})
     * @param  Pairing  $pairing  how the two sides' nodes were matched — `Structural` means at least one
     *                            artifact carried no identities, so a rename reads as remove + add
     * @param  list<string>  $disjointIdentities  node kinds both sides identified and shared no id for
     *                                            ({@see IdentityOverlap})
     * @param  list<string>  $unreferencedComponents  components nothing in either document reaches — a
     *                                                schema no operation can read, a security scheme no
     *                                                requirement names — whose changes were reported but
     *                                                stood down from breaking
     *                                                ({@see DocumentDiffer::diffComponentSchemas()})
     */
    public function __construct(
        public array $changes = [],
        public Pairing $pairing = Pairing::Identity,
        public array $disjointIdentities = [],
        public array $unreferencedComponents = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function isBreaking(): bool
    {
        return $this->breakingChanges() !== [];
    }

    /**
     * @return list<Change>
     */
    public function breakingChanges(): array
    {
        return array_values(array_filter($this->changes, static fn (Change $c): bool => $c->breaking));
    }

    /**
     * @return list<Change>
     */
    public function nonBreakingChanges(): array
    {
        return array_values(array_filter($this->changes, static fn (Change $c): bool => ! $c->breaking));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'breaking' => $this->isBreaking(),
            'pairing' => $this->pairing->value,
            'disjointIdentities' => $this->disjointIdentities,
            'unreferencedComponents' => $this->unreferencedComponents,
            'counts' => [
                'total' => count($this->changes),
                'breaking' => count($this->breakingChanges()),
            ],
            'changes' => array_map(static fn (Change $c): array => $c->toArray(), $this->changes),
        ];
    }
}
