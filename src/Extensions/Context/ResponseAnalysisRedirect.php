<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;
use Docuccino\Core\Inference\ActionRef;

/**
 * A redirect of success-body inference to another analysis target, produced by a
 * {@see ResponseAnalysisTarget}: the {@see ActionRef} whose return type is the real wire shape, plus the
 * producer to attribute the body to (`integration:laravel-actions`), so a redirect is never
 * mislabelled as plain inference.
 */
final readonly class ResponseAnalysisRedirect
{
    public function __construct(
        public ActionRef $ref,
        public string $producer,
    ) {}
}
