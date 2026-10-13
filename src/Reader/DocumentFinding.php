<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\SpecValidation\Finding;
use Stringable;

/**
 * One place a read document fails a specification: a {@see Finding}'s fields, plus which specification
 * it fails ({@see FindingSource}).
 */
final readonly class DocumentFinding implements Stringable
{
    public function __construct(
        public FindingSource $source,
        public string $pointer,
        public ?string $keyword,
        public string $message,
        public ?string $schemaPointer = null,
    ) {}

    public static function from(FindingSource $source, Finding $finding): self
    {
        return new self($source, $finding->pointer, $finding->keyword, $finding->message, $finding->schemaPointer);
    }

    /** The finding without its source — the line every spec check prints. */
    public function finding(): Finding
    {
        return new Finding($this->pointer, $this->keyword, $this->message, $this->schemaPointer);
    }

    public function __toString(): string
    {
        return '['.$this->source->value.'] '.$this->finding();
    }
}
