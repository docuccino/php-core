<?php

declare(strict_types=1);

namespace Docuccino\Core\Patch;

use Docuccino\Core\Provenance\OverrodeEntry;
use Docuccino\Core\Provenance\Provenance;
use Docuccino\Core\Provenance\ProvenanceRecord;
use Docuccino\Core\Support\JsonValue;

/**
 * Field-level precedence arbiter — every scalar write on a draft node goes through one guard, which
 * tracks the winning `(layer, producer)` per field:
 *
 * - unset field → accepted;
 * - a strictly higher `(layer, specificity)` → accepted, and the displaced value is appended to the
 *   winner's `overrode` trail;
 * - lower-or-equal over an existing owner → {@see PatchResult::Shadowed}, and the value it could not
 *   write is appended to that same trail;
 * - `null` → {@see PatchResult::NoOp}, meaning "not specified". {@see Remove::value()} is a real
 *   write that resolves to field-absent on freeze.
 *
 * A `Shadowed` result is NOT a problem report and no caller treats it as one: a higher layer winning
 * is the ladder working, and the overwhelming majority of shadows discard the value that won anyway —
 * two producers agreeing. So the trail is the whole channel. The one thing a shadow may never do is
 * disappear: a discarded value that differs from the winner is recorded like a displaced one, which is
 * what `--provenance=full` and `docuccino explain` read back.
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
            // A shadow that discards the value that won anyway is two producers agreeing, and there is
            // nothing there to have lost — recording it would bury the shadows that did lose something.
            // By value, never `!==`: on an object that is instance identity, and a JSON object a PHP
            // array cannot hold — `{}`, `{"0":"a","1":"b"}` — is minted fresh per producer, so two of
            // them writing the same one would each record a phantom entry ({@see JsonValue::same}).
            if (! JsonValue::same($value, $state->value)) {
                $state->overrode[] = new OverrodeEntry(
                    field: $field,
                    value: $this->exportValue($value),
                    producer: $by->producer,
                );
            }

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

    /**
     * Whether `$by` outranks the winning contribution of every field written here — so a producer at
     * that layer speaks over the whole node rather than only the fields it happens to touch. A guard
     * nothing has written yet answers true: there is nothing there to outrank.
     */
    public function outranksAll(Contribution $by): bool
    {
        foreach ($this->fields as $state) {
            if (! $by->outranks($state->winner)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every winning field as `(value, contribution)`, {@see Remove} sentinels omitted — so a draft that
     * takes over another's facts can replay them at the layers that wrote them, rather than at whichever
     * layer happens to be moving them.
     *
     * @return array<string, array{value: mixed, by: Contribution}>
     */
    public function contributions(): array
    {
        $out = [];

        foreach ($this->fields as $field => $state) {
            if ($state->value instanceof Remove) {
                continue;
            }

            $out[$field] = ['value' => $state->value, 'by' => $state->winner];
        }

        return $out;
    }

    /** The `producer` of the currently-winning contribution for a field, null if unset. */
    public function producerFor(string $field): ?string
    {
        return ($this->fields[$field] ?? null)?->winner->producer;
    }

    /**
     * Every producer that has written this field: the current winner first, then each one recorded in
     * its trail. A field a higher layer has since patched still names whoever built what was patched,
     * so "did any integration recover this?" survives being overridden.
     *
     * @return list<string>
     */
    public function producersFor(string $field): array
    {
        $state = $this->fields[$field] ?? null;
        if ($state === null) {
            return [];
        }

        $producers = [$state->winner->producer];

        foreach ($state->overrode as $entry) {
            if ($entry->producer !== null) {
                $producers[] = $entry->producer;
            }
        }

        return array_values(array_unique($producers));
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
