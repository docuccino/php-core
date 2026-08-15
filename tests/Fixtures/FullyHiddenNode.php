<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Hidden;

/** A plain DTO whose every property is hidden — the degradation edge of the deny-list. */
#[Hidden('secret')]
final readonly class FullyHiddenNode
{
    public function __construct(
        public string $secret,
    ) {}
}
