<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
    case Hint = 'hint';
}
