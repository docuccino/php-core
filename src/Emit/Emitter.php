<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

/**
 * The shared shape of every emitter: it names the format it produces. This is the
 * seed of the Phase 3 emitter contract (design §6) — the `emit()` half is
 * deliberately left off until the full contract (with its `EmitOptions` argument)
 * is formalised, so the concrete emitters can converge on it incrementally.
 */
interface Emitter
{
    /** The stable format identifier, e.g. `uir`, `openapi-3.2`, `openapi-3.1`. */
    public function format(): string;
}
