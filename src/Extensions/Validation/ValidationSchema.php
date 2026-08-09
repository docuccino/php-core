<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Contracts\ValidationRulesToSchema;

/**
 * The output of {@see ValidationRulesToSchema::convert()}:
 * the request JSON Schema (an object with `properties`/`required`), the media type it belongs
 * under (`multipart/form-data` once a `file`/`image` rule is seen, else `application/json`), and
 * any diagnostics raised for rules no transformer handled (design §6 — the schema stays
 * permissive, an info diagnostic names the rule).
 */
final readonly class ValidationSchema
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     */
    public function __construct(
        public array $schema,
        public string $mediaType = 'application/json',
        public array $diagnostics = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->schema === [];
    }
}
