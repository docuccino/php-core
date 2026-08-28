<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Hidden;

use Docuccino\Attributes\Hidden;

/** The base half of the inheritance pair: it carries the deny-list its subclass does not inherit. */
#[Hidden('secret')]
class BaseNode
{
    public function __construct(
        public int $id,
        public string $secret,
    ) {}
}
