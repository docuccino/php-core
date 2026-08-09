<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;

/**
 * Maps an escaping exception to an error response (design §6). A chain: the first mapper that both
 * supports the throw and returns a non-null draft wins, and null defers to the next. The engine has
 * already resolved the throw to a {@see ThrownException} carrying the FQCN and a constant-folded
 * status hint, so mappers never re-derive status from a bare type.
 */
interface ExceptionToResponse
{
    public function supports(ThrownException $exception, RouteContext $context): bool;

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ?ResponseDraft;

    /**
     * The provenance producer this mapper's contribution is recorded under, so an error response names
     * the tier that produced it instead of a blanket `inference`. An analysing mapper returns
     * `inference`, an integration `integration:<name>`, the terminal fallback `fallback`;
     * {@see Contribution::forProducer()} maps the string to a precedence layer.
     */
    public function producer(): string;
}
