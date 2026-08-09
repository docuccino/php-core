<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;

/**
 * The outcome of walking the exception→response chain for one throw ({@see RouteContext::mapThrow()}):
 * the winning mapper paired with the {@see ResponseDraft} it produced. Callers apply the draft with a
 * producer/source of their choosing — some use the mapper's own {@see ExceptionToResponse::producer()},
 * others a fixed integration producer.
 */
final readonly class MappedResponse
{
    public function __construct(
        public ExceptionToResponse $mapper,
        public ResponseDraft $draft,
    ) {}
}
