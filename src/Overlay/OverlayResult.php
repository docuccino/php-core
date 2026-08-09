<?php

declare(strict_types=1);

namespace Docuccino\Core\Overlay;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * The outcome of applying an overlay: the resulting document array plus the diagnostics produced
 * (unsupported selectors as errors, zero-match targets as warnings). Ordering follows action
 * order, which is deterministic for a given overlay.
 */
final readonly class OverlayResult
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<Diagnostic>  $diagnostics
     */
    public function __construct(
        public array $document,
        public array $diagnostics = [],
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }
}
