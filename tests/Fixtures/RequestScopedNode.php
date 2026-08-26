<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/** `request:` describes one operation's use of a body; a schema is the type, so it holds no such member. */
#[Description(text: 'Send every field.', request: true)]
final class RequestScopedNode
{
    public function __construct(public int $id) {}
}
