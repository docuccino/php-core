<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Contracts\ValidationRulesToSchema;

/**
 * The output of {@see ValidationRulesToSchema::convert()}: the request JSON Schema, the media type it
 * belongs under (`multipart/form-data` once a `file`/`image` rule is seen, else `application/json`),
 * and an info diagnostic per rule no transformer handled — those leave the schema permissive. The rule
 * set's {@see TaggedVariants} ride along, for the request body to publish once the fields are final, and
 * so does the schema its merged reading publishes ({@see RuleSet}), for any of them the body cannot.
 */
final readonly class ValidationSchema
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @param  list<TaggedVariants>  $variants
     * @param  array<string, mixed>  $merged  the schema with every variant given up; empty where there are none
     */
    public function __construct(
        public array $schema,
        public string $mediaType = 'application/json',
        public array $diagnostics = [],
        public array $variants = [],
        public array $merged = [],
    ) {}

    /** The schema as it reads with every variant given up — for a caller that publishes none of them. */
    public function withoutVariants(): self
    {
        return $this->variants === [] ? $this : new self($this->merged, $this->mediaType, $this->diagnostics);
    }

    public function isEmpty(): bool
    {
        return $this->schema === [];
    }
}
