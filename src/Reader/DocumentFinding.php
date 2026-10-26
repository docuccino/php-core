<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\SpecValidation\Finding;
use Stringable;

/**
 * One place a read document fails a specification: a {@see Finding}'s fields, plus which specification
 * it fails ({@see FindingSource}) and where its pointer is written in the text that was read
 * ({@see ReadDocument::position()}): null where the pointer names nothing written there.
 */
final readonly class DocumentFinding implements Stringable
{
    public function __construct(
        public FindingSource $source,
        public string $pointer,
        public ?string $keyword,
        public string $message,
        public ?string $schemaPointer = null,
        public ?SourcePosition $position = null,
    ) {}

    public static function from(FindingSource $source, Finding $finding, ?SourcePosition $position = null): self
    {
        return new self($source, $finding->pointer, $finding->keyword, $finding->message, $finding->schemaPointer, $position);
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
