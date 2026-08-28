<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Hidden;

use Docuccino\Attributes\Hidden;

/** A name that is the empty string — a half-written declaration, which no property can ever carry. */
#[Hidden('')]
final readonly class BlankNode
{
    public function __construct(public int $id) {}
}
