<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

/**
 * The outcome of validating a document against the bundled UIR JSON Schema.
 */
final readonly class ValidationResult
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(
        private bool $valid,
        public array $errors = [],
    ) {}

    public static function valid(): self
    {
        return new self(true);
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (ValidationError $error): string => (string) $error, $this->errors);
    }
}
