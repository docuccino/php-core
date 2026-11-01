<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/**
 * An integer-backed enum: the value set a `$ref` carries is numbers, so an example beside one has to read
 * as a number to be a member of it.
 */
enum SamplePriority: int
{
    case Low = 1;
    case High = 2;
}
