<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Declaration;

/** Declares one property; everything else about it is written in another file. */
final class ChildShape extends BaseShape
{
    public int $own = 0;
}
