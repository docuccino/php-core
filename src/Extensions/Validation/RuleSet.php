<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

/**
 * A recovered set of validation rules, keyed by field path. Keys use Laravel dot + wildcard
 * notation (`title`, `author.name`, `items.*.id`, `tags.*`); the builder turns those into nested
 * object/array schemas. Field insertion order is preserved so the emitted schema is deterministic.
 */
final readonly class RuleSet
{
    /**
     * @param  array<string, list<ValidationRule>>  $fields
     */
    public function __construct(
        public array $fields = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }
}
