<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/**
 * Inherits from a described class and declares nothing. A parent's sentence describes the PARENT, so a
 * shared base cannot put one description on every shape below it.
 */
final class InheritingNode extends DescribedNode {}
