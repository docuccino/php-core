<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Declaration;

/** A trait that uses a trait, the way a package's umbrella concern does. */
trait OuterTrait
{
    use DeepTrait;

    public int $outer = 0;
}
