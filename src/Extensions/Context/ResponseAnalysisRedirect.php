<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;
use Docuccino\Core\Inference\ActionRef;

/**
 * A redirect of success-body inference to a different analysis target, produced by a
 * {@see ResponseAnalysisTarget}. Carries the {@see ActionRef}
 * whose return type is the real wire shape plus the provenance producer to attribute the inferred
 * body to (e.g. `integration:laravel-actions`), so the redirect is never mislabelled as plain
 * inference.
 */
final readonly class ResponseAnalysisRedirect
{
    public function __construct(
        public ActionRef $ref,
        public string $producer,
    ) {}
}
