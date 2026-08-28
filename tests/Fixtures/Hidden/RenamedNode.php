<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Hidden;

use Docuccino\Attributes\Hidden;

/** A deny-list left behind by a rename: the property is `password_hash`, the attribute still says the old spelling. */
#[Hidden('passwordHash')]
final readonly class RenamedNode
{
    public function __construct(
        public int $id,
        public string $password_hash,
    ) {}
}
