<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * The status of a response whose payload decides it by its own rules — a resource or a Data object the
 * framework renders through the object's own `toResponse()`, sending exactly what returning the object bare
 * sends. Only ever the status argument of a response type, where it says "place this as the payload would
 * be placed": the status is known, it is just the payload's rather than the call site's.
 */
final readonly class PayloadStatusT extends DType
{
    public const KIND = 'payloadStatus';

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND];
    }
}
