<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

/**
 * The shared shape of every emitter: it names the format it produces.
 *
 * @internal Third-party emitters are not supported yet: {@see Formats} is a closed table and no
 * registration path accepts an emitter from outside core, so implementing this buys nothing. It is
 * promoted to public API once that path exists and `emit()`'s {@see EmitOptions} signature settles —
 * which is also why `emit()` isn't declared here and {@see ReportingEmitter} carries it instead.
 */
interface Emitter
{
    /** The stable format identifier, e.g. `uir`, `openapi-3.2`, `openapi-3.1`. */
    public function format(): string;
}
