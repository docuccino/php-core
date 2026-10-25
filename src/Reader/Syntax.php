<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/** The serialisation a document was read from. */
enum Syntax: string
{
    case Json = 'json';
    case Yaml = 'yaml';

    /** The media type registered for the syntax — YAML's is RFC 9512's — and read back as it. */
    public function mediaType(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            self::Yaml => 'application/yaml',
        };
    }
}
