<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A placeholder for a payload member whose value is the enclosing response's own HTTP status code.
 * RFC-9457's `status` member is the canonical case, but it fits any envelope where a status parameter
 * flows into the body (`['status' => $status, …]`). The engine emits it when a member's value passes
 * through the same parameter that drives the HTTP status *and* that status didn't constant-fold, so
 * no literal is knowable call-independently.
 *
 * The response-building seam resolves it against the concrete status the response is documented
 * under — 403 fills it with `403`, 404 with `404` — turning it into a {@see LiteralT} before schema
 * conversion, so the member gets `const`-pinned and the example carries the real status. It should
 * therefore never reach the schema converter; if it leaks, the built-in mapper degrades honestly to
 * `{type: integer}` with no `const` or example rather than inventing one.
 */
final readonly class StatusMarkerT extends DType
{
    public const KIND = 'statusMarker';

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND];
    }
}
