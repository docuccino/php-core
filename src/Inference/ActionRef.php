<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * Identifies an action to analyse: the file it lives in, its declaring class
 * (null for closure routes), the method name, and the line the method starts on.
 */
final readonly class ActionRef
{
    public function __construct(
        public string $file,
        public ?string $class,
        public string $method,
        public int $line = 0,
    ) {}

    /** A stable label for diagnostics and cache keys. */
    public function symbol(): string
    {
        return ($this->class ?? $this->file).'::'.$this->method;
    }
}
