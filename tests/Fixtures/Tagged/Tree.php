<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Tagged;

/**
 * A closed hierarchy that refers back to itself: a Node holds another Tree.
 *
 * @phpstan-sealed Node|Leaf
 */
interface Tree {}
