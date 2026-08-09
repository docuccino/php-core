<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

/**
 * One recovered validation rule: its lower-cased name and string parameters (`max:100` → `['100']`,
 * `in:a,b` → `['a', 'b']`). Rules are recovered statically, never executed — the adapter folds a
 * `'a|b:c'` string, a `['a', 'b:c']` array or a `Rule::*` descriptor into these. `note` carries
 * optional prose a transformer may surface in the description: an enum FQCN for a choice rule, or why
 * a descriptor couldn't be fully folded.
 */
final readonly class ValidationRule
{
    /**
     * @param  list<string>  $parameters
     */
    public function __construct(
        public string $name,
        public array $parameters = [],
        public ?string $note = null,
    ) {}

    /**
     * @param  list<string>  $parameters
     */
    public static function of(string $name, array $parameters = [], ?string $note = null): self
    {
        return new self(strtolower(trim($name)), $parameters, $note);
    }

    /** The first parameter, or null. */
    public function parameter(int $index = 0): ?string
    {
        return $this->parameters[$index] ?? null;
    }
}
