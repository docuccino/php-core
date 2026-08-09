<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * How sure the engine is a {@see ThrownException} really escapes:
 *
 *   - `certain`  — a literal `throw`, or a registry hit PHPStan corroborated with a matching type;
 *   - `declared` — from a `@throws` docblock or a Larastan stub;
 *   - `likely`   — a registry rescue of an implicit bare-`Throwable` forwarder (static
 *     `Model::findOrFail`, say) that PHPStan didn't corroborate.
 */
enum ThrowConfidence: string
{
    case Certain = 'certain';
    case Declared = 'declared';
    case Likely = 'likely';

    /**
     * Precedence when deduplicating two findings of the same exception identity: more certain wins.
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
