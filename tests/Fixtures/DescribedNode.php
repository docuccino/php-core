<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/**
 * A plain DTO that describes ITSELF to a consumer, and whose class docblock — this one — says something
 * only a maintainer wants: the pair is the point, since one of the two is published and one is not.
 */
#[Description(text: 'A single retention policy, as the billing system holds it.')]
class DescribedNode
{
    public function __construct(
        public int $id,
    ) {}
}
