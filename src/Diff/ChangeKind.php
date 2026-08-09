<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

enum ChangeKind: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';
}
