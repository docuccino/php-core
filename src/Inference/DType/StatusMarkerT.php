<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A documentation-time placeholder for a payload member whose VALUE is the enclosing response's own
 * HTTP status code — the RFC-9457 `status` member being the canonical case, but the marker is generic
 * to any envelope shape where a status parameter flows through into the body (`['status' => $status,
 * …]`). The engine emits it when a member's value passes through the same parameter that also drives
 * the response's HTTP status AND that status did not constant-fold, so no literal is knowable
 * call-independently.
 *
 * The response-building seam resolves it against the concrete status the response is documented under —
 * the 403 response fills it with `403`, the 404 with `404` — turning it into a {@see LiteralT} before
 * schema conversion (so the member schema is `const`-pinned and the assembled example carries the
 * concrete status). It therefore normally never reaches the schema converter; the built-in mapper is a
 * total, honest fallback (`{type: integer}`, no `const`/example — never fabricated) should it leak.
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
