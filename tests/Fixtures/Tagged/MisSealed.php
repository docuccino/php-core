<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Tagged;

/**
 * Names a class that does not implement it, and one that does not exist.
 *
 * @phpstan-sealed Circle|Polygon|Missing
 */
interface MisSealed {}
