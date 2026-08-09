<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

/**
 * The shared shape of every emitter: it names the format it produces. `emit()` is intentionally not
 * on here yet — its `EmitOptions` signature isn't settled, so the concrete emitters converge on it
 * before it's frozen into the interface.
 */
interface Emitter
{
    /** The stable format identifier, e.g. `uir`, `openapi-3.2`, `openapi-3.1`. */
    public function format(): string;
}
