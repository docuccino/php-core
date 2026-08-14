<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\TypeEngine;
use InvalidArgumentException;

/**
 * The closed set of framework-agnostic types the inference engine speaks. Every {@see TypeEngine}
 * result is expressed in these — never a PHPStan `Type` — so results survive serialization and a
 * process boundary. Design detail: docs/design/inference-embedding.md §5.
 *
 * `toArray()`/`fromArray()` round-trip losslessly and are path-free (no absolute paths leak into
 * a DType), so identical code always serializes to identical bytes. Class *definition* provenance
 * is not part of a DType — a return's location lives on its {@see ReturnSite} — which keeps the
 * type a cache-stable identity. `canonicalKey()` gives the total order used to sort
 * union/intersection members.
 */
abstract readonly class DType
{
    /**
     * Fixed member ordering for canonical sorting. Null sorts last so nullability
     * (`UnionT[…, NullT]`) renders with null at the tail.
     *
     * @var array<string, int>
     */
    private const KIND_ORDER = [
        ScalarT::KIND => 1,
        LiteralT::KIND => 2,
        ListT::KIND => 3,
        MapT::KIND => 4,
        ArrayShapeT::KIND => 5,
        ClassT::KIND => 6,
        EnumT::KIND => 7,
        CallableT::KIND => 8,
        IntersectionT::KIND => 9,
        UnionT::KIND => 10,
        VoidT::KIND => 11,
        NeverT::KIND => 12,
        UnknownT::KIND => 13,
        StatusMarkerT::KIND => 14,
        NullT::KIND => 15,
    ];

    /** Stable discriminator tag (also the `kind` member in `toArray()`). */
    abstract public function kind(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Deterministic total-order key: zero-padded kind ordinal, then the JSON of the canonical
     * serialization. Floats are normalised to 17 significant digits first, so the key never
     * depends on the ambient `serialize_precision` (raw `json_encode` of a float would) — 17
     * digits round-trips any IEEE-754 double, so distinct floats stay distinct.
     */
    final public function canonicalKey(): string
    {
        $ordinal = self::KIND_ORDER[$this->kind()] ?? 99;

        return sprintf('%02d:', $ordinal).(json_encode(self::normalizeFloats($this->toArray())) ?: '');
    }

    /**
     * Replaces floats with a precision- and locale-independent string, recursively.
     */
    private static function normalizeFloats(mixed $value): mixed
    {
        if (is_float($value)) {
            return sprintf('%.17g', $value);
        }

        if (is_array($value)) {
            return array_map(self::normalizeFloats(...), $value);
        }

        return $value;
    }

    /**
     * Shared canonicalisation for {@see UnionT::of()} / {@see IntersectionT::of()}: flatten nested
     * members of the same composite kind, dedupe by {@see canonicalKey()}, sort — so `A|B` and
     * `B|A` yield the same byte-stable survivors. Each caller collapses an empty/single result
     * back to a concrete type itself.
     *
     * @param  list<DType>  $members
     * @param  class-string<IntersectionT|UnionT>  $composite  the wrapper kind to flatten through
     * @return list<DType>
     */
    protected static function canonicalMembers(array $members, string $composite): array
    {
        $flat = [];
        foreach ($members as $member) {
            if ($member instanceof $composite) {
                foreach ($member->members as $inner) {
                    $flat[] = $inner;
                }

                continue;
            }
            $flat[] = $member;
        }

        $byKey = [];
        foreach ($flat as $member) {
            $byKey[$member->canonicalKey()] = $member;
        }

        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $kind = $data['kind'] ?? null;

        return match ($kind) {
            ScalarT::KIND => ScalarT::fromArray($data),
            LiteralT::KIND => LiteralT::fromArray($data),
            ListT::KIND => ListT::fromArray($data),
            MapT::KIND => MapT::fromArray($data),
            ArrayShapeT::KIND => ArrayShapeT::fromArray($data),
            ClassT::KIND => ClassT::fromArray($data),
            EnumT::KIND => EnumT::fromArray($data),
            CallableT::KIND => CallableT::fromArray($data),
            IntersectionT::KIND => IntersectionT::fromArray($data),
            UnionT::KIND => UnionT::fromArray($data),
            VoidT::KIND => new VoidT,
            NeverT::KIND => new NeverT,
            NullT::KIND => new NullT,
            StatusMarkerT::KIND => new StatusMarkerT,
            UnknownT::KIND => UnknownT::fromArray($data),
            default => throw new InvalidArgumentException(
                sprintf('Unknown DType kind: %s', is_string($kind) ? $kind : gettype($kind)),
            ),
        };
    }
}
