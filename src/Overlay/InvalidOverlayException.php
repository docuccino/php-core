<?php

declare(strict_types=1);

namespace Docuccino\Core\Overlay;

use RuntimeException;

/**
 * Raised while parsing an overlay document that is structurally invalid — a missing/unsupported
 * `overlay` version, or an action carrying neither `update` nor `remove`. Selector problems are
 * NOT parse errors: an unsupported target selector surfaces as an error diagnostic at apply time
 * (design §"OpenAPI Overlay"), never a silent skip and never an exception.
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
