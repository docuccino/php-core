<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

/** Which way a step came out, and so which of its outcomes apply: Arazzo's `onSuccess` or `onFailure`. */
enum StepResult: string
{
    case Success = 'success';
    case Failure = 'failure';
}
