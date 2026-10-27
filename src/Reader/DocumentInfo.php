<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * What a document's `info` says it is: its `title` and `version`, each as the document states it, and null
 * where it states none — no `info` object, no such member, or a value that is no text (a list, a map, a
 * boolean, null). Nothing is trimmed or repaired.
 *
 * A number is the one value given as text, spelt as {@see ReadDocument::json()} spells it: YAML reads an
 * unquoted `version: 2` as a number although OpenAPI asks for a string, and a version is too often written
 * that way to read as none. So `version: 1.0`, which YAML reads as the number one, gives `1`.
 */
final readonly class DocumentInfo
{
    public function __construct(
        public ?string $title = null,
        public ?string $version = null,
    ) {}

    /**
     * @return array{title: ?string, version: ?string}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'version' => $this->version];
    }
}
