<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/**
 * A class whose real sentence sits behind a misplaced one. `#[Description]` is repeatable, so this is
 * legal PHP, and the reader owes the author the declaration a schema can hold rather than stopping at
 * the first it cannot.
 */
#[Description(text: 'Send every field.', request: true)]
#[Description(text: 'A retention policy the billing system accepts.')]
final class RequestScopedFirstNode
{
    public function __construct(public int $id) {}
}
