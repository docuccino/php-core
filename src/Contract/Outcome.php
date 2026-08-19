<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * What checking one half of an exchange found. A `note` records the honest case where the contract
 * documents something JSON Schema cannot check — a `text/csv` body, say — so a pass never silently
 * means "nothing was looked at".
 */
final readonly class Outcome
{
    /**
     * @param  list<Violation>  $violations
     */
    private function __construct(
        public array $violations,
        public ?string $note = null,
    ) {}

    public static function passed(?string $note = null): self
    {
        return new self([], $note);
    }

    /**
     * @param  list<Violation>  $violations
     */
    public static function failed(array $violations): self
    {
        return new self($violations);
    }

    /**
     * @param  list<Violation>  $violations
     */
    public static function failedOrPassed(array $violations): self
    {
        return $violations === [] ? self::passed() : self::failed($violations);
    }

    public function ok(): bool
    {
        return $this->violations === [];
    }
}
