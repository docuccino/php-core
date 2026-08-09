<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A single constant scalar value (`'x'`, `1`, `true`, `1.5`). The `base` member
 * preserves the scalar family so `1` (int) and `'1'` (string) round-trip
 * distinctly.
 */
final readonly class LiteralT extends DType
{
    public const KIND = 'literal';

    public function __construct(public string|int|float|bool $value) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function base(): string
    {
        return match (true) {
            is_bool($this->value) => ScalarT::BOOL,
            is_int($this->value) => ScalarT::INT,
            is_float($this->value) => ScalarT::FLOAT,
            default => ScalarT::STRING,
        };
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND, 'base' => $this->base(), 'value' => $this->value];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $base = $data['base'] ?? ScalarT::STRING;
        $value = $data['value'] ?? '';

        $typed = match ($base) {
            ScalarT::BOOL => (bool) $value,
            ScalarT::INT => is_numeric($value) ? (int) $value : 0,
            ScalarT::FLOAT => is_numeric($value) ? (float) $value : 0.0,
            default => is_scalar($value) ? (string) $value : '',
        };

        return new self($typed);
    }
}
