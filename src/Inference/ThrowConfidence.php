<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * How sure the engine is a {@see ThrownException} really escapes (Spike C):
 *
 *   - `certain`  — a literal `throw`, or a registry hit PHPStan corroborated
 *     with a matching explicit type;
 *   - `declared` — sourced from a `@throws` docblock or a Larastan stub;
 *   - `likely`   — a registry rescue of an implicit bare-`Throwable` forwarder
 *     (e.g. static `Model::findOrFail`) PHPStan did not corroborate.
 */
enum ThrowConfidence: string
{
    case Certain = 'certain';
    case Declared = 'declared';
    case Likely = 'likely';

    /**
     * Precedence when deduplicating two findings of the same exception identity —
     * a more certain finding wins.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Certain => 3,
            self::Declared => 2,
            self::Likely => 1,
        };
    }
}
