<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Hidden;

use Docuccino\Attributes\Hidden;

/** A class-level `#[Hidden]` naming nothing at all: it denies nothing, so there is no name to be wrong. */
#[Hidden]
final readonly class NamelessNode
{
    public function __construct(public int $id) {}
}
