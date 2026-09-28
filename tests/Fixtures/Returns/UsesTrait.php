<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Returns;

/** Takes a method from a trait whose file imports what this one does not. */
final class UsesTrait
{
    use HasDeclaredReturn;
}
