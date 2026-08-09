<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;

/**
 * The outcome of {@see RouteContext::mapThrow()}: the winning mapper paired with the
 * {@see ResponseDraft} it produced. Callers pick the producer they apply it under — the mapper's own
 * {@see ExceptionToResponse::producer()}, or a fixed integration producer.
 */
final readonly class MappedResponse
{
    public function __construct(
        public ExceptionToResponse $mapper,
        public ResponseDraft $draft,
    ) {}
}
