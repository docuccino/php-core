<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Tagged;

/**
 * A closed hierarchy: every value typed Shape is one of these.
 *
 * @phpstan-sealed Circle|Square
 */
interface Shape {}
