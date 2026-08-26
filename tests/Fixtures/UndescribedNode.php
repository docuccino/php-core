<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/** Neither half of the declaration. */
#[Description]
final class UndescribedNode
{
    public function __construct(public int $id) {}
}
