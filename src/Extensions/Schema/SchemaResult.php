<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\Contracts\TypeToSchema;

/**
 * The output of a {@see TypeToSchema} mapper: the JSON
 * Schema fragment plus the confidence the mapper assigns it (recorded-only in v1 — feeds
 * provenance `confidence`, no behaviour acts on it yet, design §4).
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
