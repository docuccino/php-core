<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\Support\JsonValue;

/**
 * The way to read an Arazzo description — 1.0 or 1.1, JSON or YAML — that someone else wrote.
 *
 * It reads under the bounds {@see DocumentReader} reads under — nesting, and how far YAML aliases may expand
 * a document past its own size — and, like it, leaves how large a description may be to the caller.
 *
 * ```php
 * $read = (new ArazzoReader)->read($bytes, 'application/yaml');
 *
 * $read->version();                          // ArazzoVersion::V1_1, or null for a version it has no schema for
 * $read->validate()->findings[0]->position;  // SourcePosition: line and column, or null
 * $read->description()->workflows;           // list<Workflow>, core's one workflow model
 * $read->position('/workflows/0/steps/1');   // where that step is written
 * ```
 */
final class ArazzoReader
{
    /**
     * Reads $bytes as JSON or YAML, as {@see DocumentReader::read()} does.
     *
     * @throws UnreadableDocumentException when the bytes are not a document it can read
     */
    public function read(string $bytes, ?string $mediaType = null): ReadArazzo
    {
        [$graph, $syntax] = (new DocumentDecoder)->decode($bytes, $mediaType);

        /** @var array<string, mixed> $document */
        $document = array_map(JsonValue::normalize(...), get_object_vars($graph));

        return new ReadArazzo($graph, $document, $syntax, $bytes);
    }
}
