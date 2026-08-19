<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance\Explain;

use Docuccino\Core\Patch\PatchGuard;

/**
 * Every layer that reached one field on one node, lowest rung first, with exactly one winner —
 * the stack {@see PatchGuard} arbitrated, read back out of the emitted trail.
 *
 * @internal
 */
final readonly class FieldTrail
{
    /**
     * @param  list<FieldContribution>  $contributions  ordered lowest layer first
     */
    public function __construct(
        public string $field,
        public array $contributions,
    ) {}

    public function winner(): ?FieldContribution
    {
        foreach ($this->contributions as $contribution) {
            if ($contribution->won) {
                return $contribution;
            }
        }

        return null;
    }

    /** Whether more than one layer had something to say about this field. */
    public function isContested(): bool
    {
        return count($this->contributions) > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'contributions' => array_map(
                static fn (FieldContribution $contribution): array => $contribution->toArray(),
                $this->contributions,
            ),
        ];
    }
}
