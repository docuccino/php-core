<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Inference\DType\DType;

/**
 * A gated seam classifying a response payload's media type — e.g. a JSON:API resource (either family)
 * serialising as `application/vnd.api+json`. Resolved per-document like the exception-mapper chain
 * (first non-null wins, default `application/json`), so a DISABLED integration contributes no matcher
 * and the built-in inferred-responses extension reads only this chain (never a resource reflector).
 */
interface PayloadMediaTypeResolver
{
    /** The media type this payload serialises as, or null to defer to the default `application/json`. */
    public function mediaTypeFor(DType $payload): ?string;
}
