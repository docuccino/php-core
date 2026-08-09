<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\ViewerContext;

/**
 * Renders a document for human consumption (design §6). Framework-agnostic: the return type is
 * `mixed` so an adapter can hand back its own HTTP response type. Phase 3a defines the seam
 * only; the bundled Scalar viewer lands in Phase 5.
 */
interface Viewer
{
    public function render(ViewerContext $context): mixed;
}
