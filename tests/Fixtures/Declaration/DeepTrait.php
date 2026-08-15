<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Declaration;

/** A trait used by another trait — reachable only by recursing through the trait list. */
trait DeepTrait
{
    public string $deep = '';
}
