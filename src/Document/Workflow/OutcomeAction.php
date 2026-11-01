<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

/** What an outcome does: go to a step or a workflow, end the workflow, or retry the step (on failure only). */
enum OutcomeAction: string
{
    case Goto = 'goto';
    case End = 'end';
    case Retry = 'retry';
}
