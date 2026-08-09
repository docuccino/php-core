<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Ordering\ExtensionSorter;

/**
 * The ordered phases of the operation pipeline (design §5). Each {@see OperationExtension}
 * declares the phase it runs in; the pipeline executes phases in this order, and within a
 * phase orders extensions by {@see ExtensionSorter}.
 */
enum OperationPhase: int
{
    case Parameters = 10;
    case Request = 20;
    case Responses = 30;
    case Errors = 40;
    case Security = 50;
    case Overrides = 60;
    case Finalize = 70;
}
