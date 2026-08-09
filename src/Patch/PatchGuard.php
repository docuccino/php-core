<?php

declare(strict_types=1);

namespace Docuccino\Core\Patch;

use Docuccino\Core\Provenance\OverrodeEntry;
use Docuccino\Core\Provenance\Provenance;
use Docuccino\Core\Provenance\ProvenanceRecord;

/**
 * Field-level precedence arbiter — every scalar write on a draft node goes through one guard, which
 * tracks the winning `(layer, producer)` per field:
 *
 * - unset field → accepted;
 * - a strictly higher `(layer, specificity)` → accepted, and the displaced value is appended to the
 *   winner's `overrode` trail;
 * - lower-or-equal over an existing owner → {@see PatchResult::Shadowed} (the caller raises an info
 *   diagnostic); the shadowed value is discarded, never recorded;
 * - `null` → {@see PatchResult::NoOp}, meaning "not specified". {@see Remove::value()} is a real
 *   write that resolves to field-absent on freeze.
 *
 * Provenance is rebuilt on demand: fields sharing a producer/layer/source/confidence collapse into
 * one record, and only winning fields yield records — losers survive solely inside `overrode`.
 *
 * @internal
 */
final class PatchGuard
{
    /**
     * @var array<string, FieldState>
     */
    private array $fields = [];

    public function apply(string $field, mixed $value, Contribution $by): PatchResult
    {
        if ($value === null) {
            return PatchResult::NoOp;
        }

        $state = $this->fields[$field] ?? null;

        if ($state === null) {
            $this->fields[$field] = new FieldState($by, $value);

            return PatchResult::Accepted;
        }

        if (! $by->outranks($state->winner)) {
            return PatchResult::Shadowed;
        }

        $state->overrode[] = new OverrodeEntry(
            field: $field,
            value: $this->exportValue($state->value),
            producer: $state->winner->producer,
        );
        $state->winner = $by;
        $state->value = $value;

        return PatchResult::Accepted;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    /** The `producer` of the currently-winning contribution for a field, null if unset. */
    public function producerFor(string $field): ?string
    {
        return ($this->fields[$field] ?? null)?->winner->producer;
    }

    /**
     * The resolved field→value map, with {@see Remove} sentinels omitted.
     *
     * @return array<string, mixed>
     */
    public function resolved(): array
    {
        $out = [];

        foreach ($this->fields as $field => $state) {
            if ($state->value instanceof Remove) {
                continue;
            }

            $out[$field] = $state->value;
        }

        return $out;
    }

    /** Provenance records for the winning contributions, deterministically ordered. */
    public function provenance(): Provenance
    {
        /** @var array<string, array{contribution: Contribution, fields: list<string>, overrode: list<OverrodeEntry>}> $groups */
        $groups = [];

        foreach ($this->fields as $field => $state) {
            $key = $state->winner->recordKey();

            if (! isset($groups[$key])) {
                $groups[$key] = ['contribution' => $state->winner, 'fields' => [], 'overrode' => []];
            }

            $groups[$key]['fields'][] = $field;
            foreach ($state->overrode as $entry) {
                $groups[$key]['overrode'][] = $entry;
            }
        }

        $records = [];
        foreach ($groups as $group) {
            $fields = $group['fields'];
            sort($fields);

            $records[] = new ProvenanceRecord(
                producer: $group['contribution']->producer,
                layer: $group['contribution']->layer->label(),
                fields: $fields,
                source: $group['contribution']->source,
                confidence: $group['contribution']->confidence,
                overrode: $group['overrode'],
            );
        }

        usort($records, static function (ProvenanceRecord $a, ProvenanceRecord $b): int {
            return [$a->layer, $a->producer, self::sourceKey($a)]
                <=> [$b->layer, $b->producer, self::sourceKey($b)];
        });

        return new Provenance($records);
    }

    private static function sourceKey(ProvenanceRecord $record): string
    {
        $source = $record->source;

        if ($source === null) {
            return '';
        }

        return $source->file.':'.($source->line ?? 0).':'.($source->symbol ?? '');
    }

    private function exportValue(mixed $value): mixed
    {
        return $value instanceof Remove ? null : $value;
    }
}
