<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\Support\JsonValue;

/**
 * The way to read an OpenAPI document that someone else wrote — a full Docuccino artifact, an export
 * with its ids kept, or a plain OpenAPI 3.x description from any tool — as JSON or YAML.
 *
 * It reads and reports; it sets no policy. How large a document may be, and what to do with one that
 * fails validation, are the caller's to decide. What it does refuse is a document that only expands
 * past its own size once walked — YAML aliases, nodes listed far more often than they are written, or
 * nodes whose pointers and keys spell a long name again for each of them — so every answer a read
 * document gives costs in proportion to its size.
 *
 * Every enum it hands back is backed by a string a caller may store, and those strings change only as a
 * breaking change.
 *
 * ```php
 * $document = (new DocumentReader)->read($bytes, 'application/yaml');
 *
 * $document->inspect()->capability;   // Capability::Full
 * $document->validate()->isValid();
 * $document->nodes();                 // list<DocumentNode>
 * ```
 */
final class DocumentReader
{
    /**
     * Reads $bytes as JSON or YAML: as the media type says where it names one, and otherwise by the text
     * itself — JSON when it opens with `{` or `[`, YAML when it does not.
     *
     * @throws UnreadableDocumentException when the bytes are not a document it can read
     */
    public function read(string $bytes, ?string $mediaType = null): ReadDocument
    {
        [$graph, $syntax] = (new DocumentDecoder)->decode($bytes, $mediaType);

        /** @var array<string, mixed> $document */
        $document = array_map(JsonValue::normalize(...), get_object_vars($graph));

        (new NodeManifest)->admit($document, strlen($bytes));

        return new ReadDocument($graph, $document, Inspector::inspect($graph, $syntax));
    }
}
