<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;

/**
 * The step every response the {@see ExceptionToResponse} chain renders passes through before it is sent — a
 * framework's one hook for reshaping all rendered errors (design §6). {@see finalization()} and
 * {@see responses()} are asked in that order, since a replacement is written after the rendered response is
 * withdrawn, and must be one function of the same inputs.
 */
interface ErrorResponseFinalizer
{
    public function finalization(ThrownException $exception, ResponseDraft $rendered, RouteContext $context): Finalization;

    /**
     * The response(s) sent in place of the rendered one ({@see Finalization::Replaces}) or as its
     * alternatives ({@see Finalization::Extends}). Several are alternatives of one another, so they share
     * one status — the rendered response's, where it is still sent.
     *
     * @return list<ResponseDraft>
     */
    public function responses(
        ThrownException $exception,
        ResponseDraft $rendered,
        RouteContext $context,
        ComponentRegistry $components,
    ): array;

    /** The provenance producer a finalized response is recorded under ({@see ExceptionToResponse::producer()}). */
    public function producer(): string;
}
