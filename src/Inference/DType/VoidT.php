<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * The `void` type (a return path with no value).
 */
final readonly class VoidT extends DType
{
    public const KIND = 'void';

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND];
    }
}
