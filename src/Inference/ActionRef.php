<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Provenance\MessagePaths;

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

    /**
     * A stable label for stub maps and cache keys. A closure route has no class, so what identifies it
     * here is its FILE — which makes this an identity key and not something a diagnostic or a provenance
     * source may print, exactly as {@see CallableRef::target()} says of its own: compose a publishable
     * one around a relativised file instead — `RouteContext::actionSource()` is where that is done — or
     * relativise the whole label through {@see MessagePaths}.
     */
    public function symbol(): string
    {
        return ($this->class ?? $this->file).'::'.$this->method;
    }
}
