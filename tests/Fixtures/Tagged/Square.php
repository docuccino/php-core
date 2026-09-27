<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Tagged;

final readonly class Square implements Shape
{
    public string $kind;

    public function __construct(public int $side)
    {
        $this->kind = 'square';
    }
}
