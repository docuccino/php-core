<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/** `file:` names a path, and no application root reaches a schema mapper to resolve one against. */
#[Description(file: 'docs/retention.md')]
final class FiledNode
{
    public function __construct(public int $id) {}
}
