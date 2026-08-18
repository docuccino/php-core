<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

/**
 * A single schema-validation failure, anchored to a JSON pointer into the document.
 */
final readonly class ValidationError
{
    public function __construct(
        public string $pointer,
        public string $message,
    ) {}

    public function __toString(): string
    {
        $location = $this->pointer === '' ? '(root)' : $this->pointer;

        return $location.': '.$this->message;
    }
}
