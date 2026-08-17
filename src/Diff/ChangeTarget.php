<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

enum ChangeTarget: string
{
    case Operation = 'operation';
    case Parameter = 'parameter';
    case Response = 'response';
    case Schema = 'schema';
    case SecurityScheme = 'securityScheme';
    case ContentPage = 'page';
}
