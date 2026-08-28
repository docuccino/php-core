<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Hidden;

use Docuccino\Attributes\Hidden;

/**
 * One name written twice. `#[Hidden]` is not repeatable, so this is the only way an author can say the
 * same wrong thing twice — and it is one mistake, not two.
 */
#[Hidden('token', 'token')]
final readonly class RepeatedNode
{
    public function __construct(public int $id) {}
}
