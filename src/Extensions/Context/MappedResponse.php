<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Contracts\ErrorResponseFinalizer;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\ExceptionTranslator;
use Docuccino\Core\Inference\ThrownException;

/**
 * The outcome of {@see RouteContext::mapThrow()}: the winning mapper paired with the
 * {@see ResponseDraft} that is finally sent — its own, or what a finalizer made of it — and the exception an
 * {@see ExceptionTranslator} rendered in place of the throw, where one did. Callers pick the producer they
 * apply it under — {@see producer()}, or a fixed integration producer.
 */
final readonly class MappedResponse
{
    public function __construct(
        public ExceptionToResponse $mapper,
        public ResponseDraft $draft,
        public ?ErrorResponseFinalizer $finalizer = null,
        public ?ThrownException $translated = null,
    ) {}

    /** Whoever last shaped the response: the finalizer that did, or the mapper that rendered it. */
    public function producer(): string
    {
        return $this->finalizer?->producer() ?? $this->mapper->producer();
    }
}
