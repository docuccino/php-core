<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Support\PlainText;
use RuntimeException;

/**
 * Raised when two documents cannot be compared, and `$failure` says why: their identities were minted
 * by different identity-algorithm versions (the algo version is embedded in every `x-docuccino.id`), so
 * a diff would silently mis-pair nodes; or comparing them would read one document's shared nodes far
 * more often than any document needs ({@see ReferenceBudget}).
 *
 * Both versions are read off an artifact and the message goes straight to a terminal, so they carry the
 * same forgery risk the report itself does and go through {@see PlainText} for the same reason.
 */
final class IncomparableDocumentsException extends RuntimeException
{
    public function __construct(
        public readonly DiffFailure $failure,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function algoMismatch(string $old, string $new): self
    {
        return new self(DiffFailure::AlgorithmMismatch, sprintf(
            'Cannot diff documents built with different identity-algorithm versions: old=%s, new=%s. '
            .'Regenerate both with the same Docuccino version before diffing.',
            PlainText::of($old),
            PlainText::of($new),
        ));
    }

    public static function referenceExpansion(int $limit, int $size): self
    {
        return new self(DiffFailure::ReferenceExpansion, sprintf(
            'Cannot diff these documents: one of them is %d bytes and would be read for more than %d through its references. '
            .'Its shared path items, parameters, responses, request bodies and schemas are compared once for every use, '
            .'and it uses them far more often than any document needs, so the comparison was refused rather than made.',
            $size,
            $limit,
        ));
    }
}
