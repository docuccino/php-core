<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Provenance\MessagePaths;
use InvalidArgumentException;

/**
 * Identifies a callable to analyse that isn't a route action: an exception handler's `render()`, an
 * exception class's own `render()`/`toResponse()`, or a render-callback closure located by file+line.
 * Unlike {@see ActionRef} it can carry a narrowing request — a parameter name plus the exception FQCN
 * to treat it as — so a catch-all `render(Throwable $e)` is analysed once per thrown type, harvesting
 * only the return path reachable for that type (PHPStan's `instanceof` narrowing at each return site,
 * source-order first match).
 *
 * `$narrowToEvery` asks for every return the narrowed type can reach rather than the first, as a response
 * post-processor is read ({@see ReturnSite}). `$narrowProperty` narrows a property of `$this` in place of
 * a parameter — what a resource collection wraps is `$this->resource`, not an argument — and one ref
 * narrows one subject, never both.
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
        public bool $narrowToEvery = false,
        // Every reachable return is an exception the framework renders instead (an exception map), read into
        // ActionAnalysis::$throws as a throw of what it builds; a return naming no class comes back UnknownT.
        public bool $returnsExceptions = false,
        public ?string $narrowProperty = null,
    ) {
        if ($narrowParameter !== null && $narrowProperty !== null) {
            throw new InvalidArgumentException(sprintf('%s narrows either a parameter or a property of $this, not both.', $this->target()));
        }
    }

    /** A closure located by line rather than a named method. */
    public function isClosure(): bool
    {
        return $this->method === null;
    }

    /** A stable label for diagnostics, stub maps, and cache keys. */
    public function symbol(): string
    {
        // A narrowed parameter is the one the callable is handed its exception by, so the target already
        // names it; a property is not fixed by the callable, so its name joins the key.
        $subject = $this->narrowProperty !== null ? $this->narrowedSubject().' ' : '';
        $symbol = $this->narrowType !== null ? $this->target().'#'.$subject.$this->narrowType : $this->target();

        if ($this->returnsExceptions) {
            return $symbol.'#exceptions';
        }

        return $this->narrowToEvery ? $symbol.'#every' : $symbol;
    }

    /** What is narrowed, as source spells it — `$e` or `$this->resource` — or null where nothing is. */
    public function narrowedSubject(): ?string
    {
        if ($this->narrowProperty !== null) {
            return '$this->'.$this->narrowProperty;
        }

        return $this->narrowParameter === null ? null : '$'.$this->narrowParameter;
    }

    /**
     * The callable's identity without the per-narrow suffix, so it's the same across every thrown type
     * it's analysed for. Lets the handler tier summarise deferrals per callback instead of one
     * diagnostic per exception type.
     *
     * A closure has no class, so what identifies it here is its FILE — which makes this an identity
     * key and not something a diagnostic may print: relativise it through {@see MessagePaths} first.
     */
    public function target(): string
    {
        return ($this->class ?? $this->file).'::'.($this->method ?? 'closure@'.$this->line);
    }
}
