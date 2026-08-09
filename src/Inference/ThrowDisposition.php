<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * What a {@see ThrownException} is *for* (Spike C finding on origin):
 *
 *   - `signal`   — document as an API error (registry hit, literal throw, or a
 *     project-declared exception);
 *   - `internal` — concrete but HTTP-irrelevant (maps to 500), e.g. a PSR-16
 *     `InvalidArgumentException` behind a vendor call — demoted, not surfaced
 *     as an API error;
 *   - `dropped`  — an implicit bare `Throwable`; counted and logged at verbose
 *     only, never emitted as an API error.
 */
enum ThrowDisposition: string
{
    case Signal = 'signal';
    case Internal = 'internal';
    case Dropped = 'dropped';
}
