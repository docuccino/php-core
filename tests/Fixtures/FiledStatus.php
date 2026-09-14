<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/** `file:` on an enum, which reaches a schema mapper with no application root to resolve a path against. */
#[Description(file: 'docs/status.md')]
enum FiledStatus: string
{
    case Draft = 'draft';
}
