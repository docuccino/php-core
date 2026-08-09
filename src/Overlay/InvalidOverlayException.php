<?php

declare(strict_types=1);

namespace Docuccino\Core\Overlay;

use RuntimeException;

/**
 * Raised when an overlay document is structurally invalid — a missing or unsupported `overlay`
 * version, an action with neither `update` nor `remove`. Selector problems aren't parse errors: an
 * unsupported target surfaces as an error diagnostic at apply time, never an exception and never a
 * silent skip.
 */
final class InvalidOverlayException extends RuntimeException
{
    public static function unsupportedVersion(string $version): self
    {
        return new self(sprintf(
            'Unsupported overlay version "%s"; Docuccino applies OpenAPI Overlay 1.0 documents (expected "1.0.x").',
            $version,
        ));
    }

    public static function missingVersion(): self
    {
        return new self('Overlay document is missing the required "overlay" version member.');
    }

    public static function actionWithoutOperation(int $index): self
    {
        return new self(sprintf('Overlay action #%d has neither an "update" nor a "remove" operation.', $index));
    }

    public static function actionWithoutTarget(int $index): self
    {
        return new self(sprintf('Overlay action #%d is missing the required "target" selector.', $index));
    }

    public static function malformedActions(): self
    {
        return new self('Overlay document "actions" must be a JSON array of action objects.');
    }
}
