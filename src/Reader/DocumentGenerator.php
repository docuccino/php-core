<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * What a full document says built it: `x-docuccino.generator`. Each member is null where the document
 * does not state it as a string — a document written by someone else is reported, never repaired.
 */
final readonly class DocumentGenerator
{
    public function __construct(
        public ?string $name,
        public ?string $version,
        public ?string $specVersion,
        public ?string $schema,
    ) {}
}
