<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use RuntimeException;
use Throwable;

/**
 * The bytes handed to {@see DocumentReader::read()} are not a document it can read. `$reason` is a
 * sentence for the person who uploaded them; `$failure` is the same answer for code.
 *
 * `$position` is where the text is at fault, where one place is: the first byte that is not UTF-8, where
 * JSON stops parsing, where a document nests past the limit, or the value JSON cannot represent. `$parsedLine`
 * is that position's line, or the line where symfony/yaml stopped reading YAML, which says no column. Both
 * are null for a refusal about the document as a whole, such as how far its aliases expand it.
 */
final class UnreadableDocumentException extends RuntimeException
{
    public readonly ?int $parsedLine;

    public function __construct(
        public readonly ReadFailure $failure,
        public readonly string $reason,
        ?int $parsedLine = null,
        ?Throwable $previous = null,
        public readonly ?SourcePosition $position = null,
    ) {
        $this->parsedLine = $position->line ?? $parsedLine;

        parent::__construct($this->parsedLine === null ? $reason : sprintf('%s (line %d)', $reason, $this->parsedLine), 0, $previous);
    }
}
