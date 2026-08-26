<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/** Both halves of the declaration, so it says nothing certain. */
#[Description(text: 'Something', file: 'docs/retention.md')]
final class OverdescribedNode
{
    public function __construct(public int $id) {}
}
