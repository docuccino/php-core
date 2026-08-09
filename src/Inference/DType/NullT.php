<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * The `null` type. Nullability is modelled as `UnionT[..., NullT]`.
 */
final readonly class NullT extends DType
{
    public const KIND = 'null';

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND];
    }
}
