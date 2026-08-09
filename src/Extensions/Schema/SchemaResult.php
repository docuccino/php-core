<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\Contracts\TypeToSchema;

/**
 * The output of a {@see TypeToSchema} mapper: the JSON Schema fragment plus the mapper's confidence in
 * it. Confidence is recorded only — it feeds provenance, and nothing branches on it yet.
 */
final readonly class SchemaResult
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public array $schema,
        public float $confidence = 1.0,
    ) {}
}
