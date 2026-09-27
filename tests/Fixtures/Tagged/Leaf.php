<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Tagged;

final readonly class Leaf implements Tree
{
    public string $kind;

    public function __construct(public int $value)
    {
        $this->kind = 'leaf';
    }
}
