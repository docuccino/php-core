<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

use InvalidArgumentException;

/**
 * A non-constant scalar: `string`, `int`, `float`, or `bool`.
 */
final readonly class ScalarT extends DType
{
    public const KIND = 'scalar';

    public const STRING = 'string';

    public const INT = 'int';

    public const FLOAT = 'float';

    public const BOOL = 'bool';

    private const SCALARS = [self::STRING, self::INT, self::FLOAT, self::BOOL];

    public function __construct(public string $scalar)
    {
        if (! in_array($scalar, self::SCALARS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown scalar type: %s', $scalar));
        }
    }

    public static function string(): self
    {
        return new self(self::STRING);
    }

    public static function int(): self
    {
        return new self(self::INT);
    }

    public static function float(): self
    {
        return new self(self::FLOAT);
    }

    public static function bool(): self
    {
        return new self(self::BOOL);
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND, 'scalar' => $this->scalar];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $scalar = $data['scalar'] ?? null;

        return new self(is_string($scalar) ? $scalar : self::STRING);
    }
}
