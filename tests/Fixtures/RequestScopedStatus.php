<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/** `request:` on an enum: one operation's use of a body, where an enum is a type wherever it appears. */
#[Description(text: 'Send a stage the workflow has reached.', request: true)]
enum RequestScopedStatus: string
{
    case Draft = 'draft';
}
