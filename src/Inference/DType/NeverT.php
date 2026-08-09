<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * The `never` type (a path that always throws or exits).
 */
final readonly class NeverT extends DType
{
    public const KIND = 'never';

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND];
    }
}
