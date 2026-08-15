<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Declaration;

/** A parent that contributes both a property of its own and a trait's. */
class BaseShape
{
    use OuterTrait;

    public string $inherited = '';
}
