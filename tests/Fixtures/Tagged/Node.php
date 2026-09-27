<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Tagged;

final readonly class Node implements Tree
{
    public string $kind;

    public function __construct(public Tree $child)
    {
        $this->kind = 'node';
    }
}
