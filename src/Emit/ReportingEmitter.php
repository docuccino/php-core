<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Document\UirDocument;

/**
 * The shape the built-in emitters converge on: emit, and say what the format could not carry.
 *
 * Separate from {@see Emitter} on purpose. `Emitter` is public API whose `emit()` is deliberately
 * still unfrozen, and every emitter added since has moved {@see EmitOptions} again — so the uniform
 * call lives here, `@internal`, where {@see Formats} can rely on it without spending the extension
 * surface on a signature that has not settled.
 *
 * `$options` carries no default here: {@see UirEmitter} defaults its provenance to `Full` and the
 * OpenAPI emitters default theirs to `None`, so a default on the shared contract would quietly mean
 * something different per format. Callers state what they want.
 *
 * @internal
 */
interface ReportingEmitter extends Emitter
{
    public function emitWithReport(UirDocument $document, EmitOptions $options): EmitResult;
}
