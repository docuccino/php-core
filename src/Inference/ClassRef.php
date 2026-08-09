<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * Identifies a class to expand via {@see TypeEngine::classMetadata()}. The file
 * is optional — the engine resolves it through reflection when omitted.
 */
final readonly class ClassRef
{
    public function __construct(
        public string $fqcn,
        public ?string $file = null,
    ) {}
}
