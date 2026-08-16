<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Support\PlainText;
use RuntimeException;

/**
 * Raised when two documents cannot be compared because their identities were minted by
 * different identity-algorithm versions (the algo version is embedded in every `x-docuccino.id`).
 * A cross-version diff would silently mis-pair nodes, so the differ refuses instead.
 *
 * Both versions are read off an artifact and the message goes straight to a terminal, so they carry the
 * same forgery risk the report itself does and go through {@see PlainText} for the same reason.
 */
final class IncomparableDocumentsException extends RuntimeException
{
    public static function algoMismatch(string $old, string $new): self
    {
        return new self(sprintf(
            'Cannot diff documents built with different identity-algorithm versions: old=%s, new=%s. '
            .'Regenerate both with the same Docuccino version before diffing.',
            PlainText::of($old),
            PlainText::of($new),
        ));
    }
}
