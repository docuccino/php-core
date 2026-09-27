<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Tagged;

/**
 * An abstract base sealed with Psalm's spelling of the same tag.
 *
 * @psalm-inheritors Line|Point
 */
abstract class Figure
{
    public string $label = '';
}
