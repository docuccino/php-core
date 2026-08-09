<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

/**
 * An emitted artifact plus the diagnostics produced while building it.
 */
final readonly class EmitResult
{
    public function __construct(
        public string $output,
        public EmitReport $report,
    ) {}
}
