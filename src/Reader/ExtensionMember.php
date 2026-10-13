<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * A member of the `x-docuccino` extension a document can carry. `provenance`, `mock` and `uses` sit on a
 * node; `content`, `workflows` and `diagnostics` on the document root.
 */
enum ExtensionMember: string
{
    case Provenance = 'provenance';
    case Content = 'content';
    case Mock = 'mock';
    case Workflows = 'workflows';
    case Diagnostics = 'diagnostics';
    case Uses = 'uses';
}
