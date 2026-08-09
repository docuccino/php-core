<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use RuntimeException;

/**
 * Raised when two documents cannot be compared because their identities were minted by
 * different identity-algorithm versions (the algo version is embedded in every `x-docuccino.id`).
 * A cross-version diff would silently mis-pair nodes, so the differ refuses instead.
 */
final class IncomparableDocumentsException extends RuntimeException
{
    public static function algoMismatch(string $old, string $new): self
    {
        return new self(sprintf(
            'Cannot diff documents built with different identity-algorithm versions: old=%s, new=%s. '
            .'Regenerate both with the same Docuccino version before diffing.',
            $old,
            $new,
        ));
    }
}
