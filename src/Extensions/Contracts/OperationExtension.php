<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * A phased contributor to one operation (design §5/§6). Every write it makes on the
 * {@see OperationDraft} goes through the draft's PatchGuard, so field precedence and
 * provenance are enforced regardless of which extension runs first.
 */
interface OperationExtension
{
    public function phase(): OperationPhase;

    public function handle(OperationDraft $operation, RouteContext $context): void;
}
