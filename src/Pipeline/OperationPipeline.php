<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\ResolvedExtensions;

/**
 * Runs the phased operation extensions in order (design §5): every phase in
 * {@see OperationPhase} declaration order, and within a phase the extensions in their resolved
 * (topologically sorted) order. All writes flow through the draft's PatchGuard, so precedence
 * and provenance hold regardless of execution order.
 *
 * @internal
 */
final class OperationPipeline
{
    public function run(OperationDraft $operation, RouteContext $context, ResolvedExtensions $extensions): void
    {
        foreach (OperationPhase::cases() as $phase) {
            foreach ($extensions->operationExtensionsFor($phase) as $extension) {
                $extension->handle($operation, $context);
            }
        }
    }
}
