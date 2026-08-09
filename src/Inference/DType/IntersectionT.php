<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * An intersection of two or more member types (`A&B`). Canonical like
 * {@see UnionT}: flattened, deduped, sorted. Accessory-type stripping happens
 * in the translator before construction; a single survivor collapses to itself.
 */
final readonly class IntersectionT extends DType
{
    public const KIND = 'intersection';

    /**
     * @param  list<DType>  $members
     */
    public function __construct(public array $members) {}

    /**
     * @param  list<DType>  $members
     */
    public static function of(array $members): DType
    {
        $unique = self::canonicalMembers($members, self::class);

        return match (count($unique)) {
            0 => new UnknownT('empty intersection'),
            1 => $unique[0],
            default => new self($unique),
        };
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'members' => array_map(static fn (DType $t): array => $t->toArray(), $this->members),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): DType
    {
        $members = $data['members'] ?? [];

        return self::of(
            is_array($members)
                ? array_values(array_map(
                    static fn (mixed $t): DType => is_array($t) ? DType::fromArray($t) : new UnknownT('malformed intersection member'),
                    $members,
                ))
                : [],
        );
    }
}
