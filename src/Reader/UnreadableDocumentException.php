<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use RuntimeException;
use Throwable;

/**
 * The bytes handed to {@see DocumentReader::read()} are not a document it can read. `$reason` is a
 * sentence for the person who uploaded them; `$failure` is the same answer for code; `$parsedLine` is
 * where the parser stopped, when it says.
 */
final class UnreadableDocumentException extends RuntimeException
{
    public function __construct(
        public readonly ReadFailure $failure,
        public readonly string $reason,
        public readonly ?int $parsedLine = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($parsedLine === null ? $reason : sprintf('%s (line %d)', $reason, $parsedLine), 0, $previous);
    }
}
