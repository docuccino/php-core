<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * Identifies a callable to analyse that isn't a route action: an exception handler's `render()`, an
 * exception class's own `render()`/`toResponse()`, or a render-callback closure located by file+line.
 * Unlike {@see ActionRef} it can carry a narrowing request — a parameter name plus the exception FQCN
 * to treat it as — so a catch-all `render(Throwable $e)` is analysed once per thrown type, harvesting
 * only the return path reachable for that type (PHPStan's `instanceof` narrowing at each return site,
 * source-order first match).
 */
final readonly class CallableRef
{
    public function __construct(
        public string $file,
        public ?string $class,
        public ?string $method,
        public int $line = 0,
        public ?string $narrowParameter = null,
        public ?string $narrowType = null,
    ) {}

    /** A closure located by line rather than a named method. */
    public function isClosure(): bool
    {
        return $this->method === null;
    }

    /** A stable label for diagnostics, stub maps, and cache keys. */
    public function symbol(): string
    {
        return $this->narrowType !== null ? $this->target().'#'.$this->narrowType : $this->target();
    }

    /**
     * The callable's identity without the per-narrow suffix, so it's the same across every thrown type
     * it's analysed for. Lets the handler tier summarise deferrals per callback instead of one
     * diagnostic per exception type.
     */
    public function target(): string
    {
        return ($this->class ?? $this->file).'::'.($this->method ?? 'closure@'.$this->line);
    }
}
