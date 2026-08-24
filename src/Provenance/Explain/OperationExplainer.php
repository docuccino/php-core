<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance\Explain;

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Provenance\OverrodeEntry;
use Docuccino\Core\Provenance\Provenance;
use Docuccino\Core\Provenance\ProvenanceRecord;

/**
 * Reads the `x-docuccino.provenance` trail back off one operation and rebuilds the field-by-field
 * stack that produced it: for every field a layer wrote, who won it and which values it displaced.
 *
 * The trail records only winners — a losing value survives solely inside the winner's `overrode`
 * list, whether it was displaced by a higher layer or shadowed by one it could not outrank — so a
 * stack is only ever as complete as the emit level the document was built at. Nothing here re-derives
 * anything: it reads what the build already wrote down, which is also why a losing contribution never
 * carries a source: `overrode` keeps a field, a value and a producer, and has nowhere to record where
 * that value came from.
 *
 * `$ref`s into `components` are followed, so a body the operation only points at still gets read.
 * Each component is read at most once however many times the operation reaches it.
 *
 * @internal
 */
final class OperationExplainer
{
    /** Node labels this prefix sorts into their reading order — the shape of an OAS operation. */
    private const array SECTIONS = ['parameters' => 1, 'requestBody' => 2, 'responses' => 3];

    /**
     * Every node under this operation that recorded provenance, in reading order.
     *
     * @param  array<string, mixed>  $document
     * @return list<ExplainedNode>
     */
    public function explain(array $document, string $path, string $method): array
    {
        $operation = Pointer::read($document, ['paths', $path, $method]);
        if (! is_array($operation)) {
            return [];
        }

        /** @var array<string, mixed> $operation */
        $nodes = [];
        $refs = [];
        $this->walk($document, $operation, 'operation', '', Pointer::of(['paths', $path, $method]), $nodes, $refs);

        $seen = [];
        while ($refs !== []) {
            $ref = array_shift($refs);
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;

            $segments = self::refSegments($ref);
            if ($segments === null) {
                continue;
            }

            $target = Pointer::read($document, $segments);
            if (! is_array($target)) {
                continue;
            }

            /** @var array<string, mixed> $target */
            $this->walk($document, $target, $ref, $ref, Pointer::of($segments), $nodes, $refs);
        }

        usort($nodes, static fn (ExplainedNode $a, ExplainedNode $b): int => [self::rank($a->label), $a->label] <=> [self::rank($b->label), $b->label]);

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $node
     * @param  string  $childPrefix  what a child's label is built on — empty under the operation, so
     *                               the labels read as `responses.201` rather than `operation.responses.201`
     * @param  list<ExplainedNode>  $nodes
     * @param  list<string>  $refs
     */
    private function walk(array $document, array $node, string $label, string $childPrefix, string $pointer, array &$nodes, array &$refs): void
    {
        $ref = self::refOf($node);
        $trails = $this->trails($document, $node, $ref);

        if ($trails !== []) {
            $nodes[] = new ExplainedNode($label, $pointer, $trails, $ref);
        }

        if ($ref !== null) {
            $refs[] = $ref;
        }

        foreach ($node as $key => $value) {
            // The extension member is metadata about this node, not a node of its own.
            if ($key === 'x-docuccino' || ! is_array($value)) {
                continue;
            }

            $key = (string) $key;

            if (array_is_list($value)) {
                foreach ($value as $index => $child) {
                    if (! is_array($child)) {
                        continue;
                    }

                    /** @var array<string, mixed> $child */
                    $segment = $key.'.'.self::itemName($child, $index);
                    $this->walk($document, $child, self::join($childPrefix, $segment), self::join($childPrefix, $segment), Pointer::append(Pointer::append($pointer, $key), $index), $nodes, $refs);
                }

                continue;
            }

            /** @var array<string, mixed> $value */
            $this->walk($document, $value, self::join($childPrefix, $key), self::join($childPrefix, $key), Pointer::append($pointer, $key), $nodes, $refs);
        }
    }

    /**
     * The field stacks this node records, field name order.
     *
     * A field's value is looked for on the node, then in its `facts` — where a contribution that
     * resolved to something other than a member of its own node writes what it decided — then on the
     * component the node points at, since a node written as a bare `$ref` publishes its description
     * and its content from there.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $node
     * @return list<FieldTrail>
     */
    private function trails(array $document, array $node, ?string $ref): array
    {
        $extension = $node['x-docuccino'] ?? null;
        $provenance = is_array($extension) ? ($extension['provenance'] ?? null) : null;
        if (! is_array($provenance) || $provenance === []) {
            return [];
        }

        $facts = is_array($extension['facts'] ?? null) ? $extension['facts'] : [];
        $target = $ref === null ? [] : self::target($document, $ref);

        /** @var array<string, list<FieldContribution>> $stacks */
        $stacks = [];

        foreach (Provenance::fromArray(array_values($provenance))->records as $record) {
            foreach ($record->fields as $field) {
                $value = self::valueOf($node, $facts, $target, $field);

                $stacks[$field][] = new FieldContribution(
                    producer: $record->producer,
                    layer: self::winningLayer($record),
                    won: true,
                    value: $value,
                    source: $record->source,
                    confidence: $record->confidence,
                    // A winning field that is nowhere to be found resolved to field-absent: the layer
                    // wrote a Remove, which is a decision about the field rather than a missing value.
                    removed: $value === null,
                );
            }

            foreach ($record->overrode as $entry) {
                $stacks[$entry->field][] = self::shadowed($entry);
            }
        }

        ksort($stacks);

        $trails = [];
        foreach ($stacks as $field => $contributions) {
            // Highest rung first, so the value the document publishes is always the top line and
            // everything under it is what that value beat. Two contributions can share a rung —
            // specificity is what separated them — so the winner is pulled above its own shadow.
            usort($contributions, static function (FieldContribution $a, FieldContribution $b): int {
                return [$b->layer->value, $b->won, $a->producer] <=> [$a->layer->value, $a->won, $b->producer];
            });

            $trails[] = new FieldTrail((string) $field, $contributions);
        }

        return $trails;
    }

    /**
     * A displaced value, which the trail remembers by producer alone. The producer is mapped back to
     * its rung by {@see Contribution::forProducer()} — the same mapping the build ranked it with, so
     * the ladder a reader is shown is the one that actually decided the field.
     */
    private static function shadowed(OverrodeEntry $entry): FieldContribution
    {
        $producer = $entry->producer ?? '';

        return new FieldContribution(
            producer: $producer === '' ? '(unrecorded)' : $producer,
            layer: Contribution::forProducer($producer)->layer,
            won: false,
            value: $entry->value,
            // The trail writes no `value` for a displaced Remove, so a loser with none had itself
            // resolved the field to absent.
            removed: $entry->value === null,
        );
    }

    /** The record's own `layer`, degrading to what its producer implies for a document we didn't write. */
    private static function winningLayer(ProvenanceRecord $record): Layer
    {
        return Layer::fromLabel($record->layer) ?? Contribution::forProducer($record->producer)->layer;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<array-key, mixed>  $facts
     * @param  array<array-key, mixed>  $target  the component the node points at, or empty
     */
    private static function valueOf(array $node, array $facts, array $target, string $field): mixed
    {
        if (array_key_exists($field, $node)) {
            return $node[$field];
        }

        return $facts[$field] ?? $target[$field] ?? null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<array-key, mixed>
     */
    private static function target(array $document, string $ref): array
    {
        $segments = self::refSegments($ref);
        $node = $segments === null ? null : Pointer::read($document, $segments);

        return is_array($node) ? $node : [];
    }

    /**
     * How a reader names one entry of a list. A parameter goes by the `in:name` pair that tells
     * parameters apart — the same vocabulary the diff speaks — and anything else by its index.
     *
     * @param  array<string, mixed>  $child
     */
    private static function itemName(array $child, int|string $index): string
    {
        $name = $child['name'] ?? null;
        $in = $child['in'] ?? null;

        return is_string($name) && is_string($in) && $name !== '' ? $in.':'.$name : (string) $index;
    }

    private static function join(string $prefix, string $segment): string
    {
        return $prefix === '' ? $segment : $prefix.'.'.$segment;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function refOf(array $node): ?string
    {
        $ref = $node['$ref'] ?? null;

        return is_string($ref) && str_starts_with($ref, '#/') ? $ref : null;
    }

    /**
     * @return list<string>|null
     */
    private static function refSegments(string $ref): ?array
    {
        $body = substr($ref, 2);
        if ($body === '') {
            return null;
        }

        return array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', $body),
        );
    }

    /** Reading order: the operation, then its sections in OAS order, then whatever it points at. */
    private static function rank(string $label): int
    {
        if ($label === 'operation') {
            return 0;
        }

        if (str_starts_with($label, '#/')) {
            return 5;
        }

        foreach (self::SECTIONS as $section => $rank) {
            if ($label === $section || str_starts_with($label, $section.'.')) {
                return $rank;
            }
        }

        return 4;
    }
}
