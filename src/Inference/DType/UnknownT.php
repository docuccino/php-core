<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * An unresolvable type. Always carries a human-readable `reason` (mixed,
 * template without a bound, translation budget exhausted, …) so a diagnostic
 * can point at exactly why inference gave up. Keeps consumers total.
 */
final readonly class UnknownT extends DType
{
    public const KIND = 'unknown';

    public function __construct(public string $reason) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND, 'reason' => $this->reason];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $reason = $data['reason'] ?? '';

        return new self(is_string($reason) ? $reason : '');
    }
}
