<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\ThrownException;

/**
 * The step a throw passes through before any {@see ExceptionToResponse} renders it — a framework's hook for
 * swapping one exception for another (design §6). What is rendered, and what every finalizer is handed, is
 * the exception it answers with; the first translator to answer wins, and nothing re-translates its answer.
 */
interface ExceptionTranslator
{
    /**
     * The exception rendered in place of `$exception` — its class and status, with the throw's own call chain,
     * confidence and disposition — or null where this translator leaves the throw as it is.
     */
    public function translate(ThrownException $exception, RouteContext $context): ?ThrownException;
}
