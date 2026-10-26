<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * Where something is written in the text a document was read from: a line and a column, both counted from
 * 1. A column counts characters (Unicode code points), a tab as one, and a line ends at a line feed, a
 * carriage return, or the two together; a byte order mark is not a column.
 */
final readonly class SourcePosition
{
    public function __construct(
        public int $line,
        public int $column,
    ) {}

    /**
     * The position as values a store or an API response can hold.
     *
     * @return array{line: int, column: int}
     */
    public function toArray(): array
    {
        return ['line' => $this->line, 'column' => $this->column];
    }
}
