<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\ViewerContext;

/**
 * Renders a document for human consumption (design §6). Framework-agnostic — the return type is
 * `mixed` so an adapter can hand back its own HTTP response type.
 *
 * {@see name()} is the id a document's `viewer.driver` selects this viewer by, which is what keeps
 * the choice out of config as a class reference. A viewer that ships browser assets also implements
 * {@see ViewerAssets}.
 */
interface Viewer
{
    /** The stable driver id, e.g. `scalar`. Lowercase, and a function of the viewer, not the build. */
    public function name(): string;

    public function render(ViewerContext $context): mixed;
}
