<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * What a {@see ThrownException} is *for*:
 *
 *   - `signal`    — document it as an API error (registry hit, literal throw, project-declared);
 *   - `assertion` — the application raises it with no HTTP status, only where its own guarantees fail: an
 *     exception of PHP's `LogicException` family, or one reached only past a failed type test. No request a
 *     correctly wired application receives gets it, so it is documented only at a status the application
 *     renders it with, never as the 500 it would otherwise fall back to;
 *   - `internal`  — concrete but HTTP-irrelevant, maps to 500. A PSR-16 `InvalidArgumentException`
 *     behind a vendor call gets demoted here rather than surfaced as an API error;
 *   - `dropped`   — an implicit bare `Throwable`; counted and logged at verbose, never emitted.
 */
enum ThrowDisposition: string
{
    case Signal = 'signal';
    case Assertion = 'assertion';
    case Internal = 'internal';
    case Dropped = 'dropped';

    /**
     * Precedence when deduplicating two findings of the same exception identity, ahead of confidence: a
     * response one site really sends is not unsent by another site raising the same class as plumbing or
     * as an assertion, whichever the analysis met first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Signal => 4,
            self::Assertion => 3,
            self::Internal => 2,
            self::Dropped => 1,
        };
    }
}
