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
     */
    public function __construct(
        public array $changes = [],
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
            'counts' => [
                'total' => count($this->changes),
                'breaking' => count($this->breakingChanges()),
            ],
            'changes' => array_map(static fn (Change $c): array => $c->toArray(), $this->changes),
        ];
    }
}
