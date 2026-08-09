<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * What a {@see ThrownException} is *for*:
 *
 *   - `signal`   — document it as an API error (registry hit, literal throw, project-declared);
 *   - `internal` — concrete but HTTP-irrelevant, maps to 500. A PSR-16 `InvalidArgumentException`
 *     behind a vendor call gets demoted here rather than surfaced as an API error;
 *   - `dropped`  — an implicit bare `Throwable`; counted and logged at verbose, never emitted.
 */
enum ThrowDisposition: string
{
    case Signal = 'signal';
    case Internal = 'internal';
    case Dropped = 'dropped';
}
