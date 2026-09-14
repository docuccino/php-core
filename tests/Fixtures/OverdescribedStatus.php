<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/** Both halves of the declaration, on a class that happens to be an enum, so it says nothing certain. */
#[Description(text: 'Something', file: 'docs/status.md')]
enum OverdescribedStatus: string
{
    case Draft = 'draft';
}
