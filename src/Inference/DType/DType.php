<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\TypeEngine;
use InvalidArgumentException;

/**
 * The closed set of framework-agnostic types the inference engine speaks
 * (design §5). Every {@see TypeEngine} result is
 * expressed in these — never a PHPStan `Type` — so results are serializable
 * across worker and cache boundaries.
 *
 * Serialization contract: `toArray()`/`fromArray()` round-trip losslessly and
 * are path-free (no absolute file paths leak into a DType), so the same code
 * always produces byte-identical serialized types. Class *definition*
 * provenance is deliberately NOT part of a DType — a return's location lives on
 * its {@see ReturnSite}, keeping the type a clean,
 * cache-stable identity.
 *
 * `canonicalKey()` gives a total order used to sort union/intersection members
 * deterministically.
 */
abstract readonly class DType
{
    /**
     * Fixed member ordering used for canonical sorting. Null sorts last so that
     * nullability (`UnionT[..., NullT]`) renders with null at the tail.
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
     * A deterministic total-order key: kind ordinal (zero-padded) followed by
     * the JSON of the type's canonical serialization.
     *
     * Float literals are normalised to a fixed 17-significant-digit form BEFORE
     * encoding, so the key never depends on the ambient `serialize_precision` ini
     * (raw `json_encode` of a float would). 17 significant digits round-trips any
     * IEEE-754 double, keeping distinct floats distinct — this mirrors the
     * precision-independence the canonical serializer enforces for output.
     */
    final public function canonicalKey(): string
    {
        $ordinal = self::KIND_ORDER[$this->kind()] ?? 99;

        return sprintf('%02d:', $ordinal).(json_encode(self::normalizeFloats($this->toArray())) ?: '');
    }

    /**
     * Recursively replace any float with a precision- and locale-independent
     * string so canonical keys are stable across `serialize_precision` settings.
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
     * Shared canonicalisation for {@see UnionT::of()} / {@see IntersectionT::of()}:
     * flatten nested members of the same composite kind, deduplicate by
     * {@see canonicalKey()}, then sort — so `A|B` and `B|A` yield identical,
     * byte-stable survivors. Collapsing an empty/single result back to a concrete
     * type is left to each caller.
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
