<?php

declare(strict_types=1);

namespace Docuccino\Core\Overlay;

use RuntimeException;

/**
 * Thrown internally by {@see TargetResolver} when a target uses JSONPath syntax outside the
 * documented supported subset (see {@see TargetResolver}). {@see OverlayApplier} catches it and
 * emits an error diagnostic — an unsupported selector is surfaced, never silently skipped.
 */
final class UnsupportedSelectorException extends RuntimeException
{
    public static function for(string $target, string $reason): self
    {
        return new self(sprintf('Unsupported overlay target selector "%s": %s', $target, $reason));
    }
}
