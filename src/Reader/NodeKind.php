<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/** What a {@see DocumentNode} is. Declaration order is the order {@see ReadDocument::nodes()} lists kinds in. */
enum NodeKind: string
{
    case Operation = 'operation';
    case Parameter = 'parameter';
    case Response = 'response';
    case Schema = 'schema';
    case Page = 'page';
    case Workflow = 'workflow';
}
