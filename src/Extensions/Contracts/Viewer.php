<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\ViewerContext;

/**
 * Renders a document for human consumption (design §6). Framework-agnostic — the return type is
 * `mixed` so an adapter can hand back its own HTTP response type.
 */
interface Viewer
{
    public function render(ViewerContext $context): mixed;
}
