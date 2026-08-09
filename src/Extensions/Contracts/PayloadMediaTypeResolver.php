<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Inference\DType\DType;

/**
 * A gated seam classifying a response payload's media type — a JSON:API resource serialising as
 * `application/vnd.api+json`, say. Resolved per-document, first non-null wins and `application/json` is
 * the default, so the inferred-responses extension reads this chain and never a resource reflector.
 */
interface PayloadMediaTypeResolver
{
    /** The media type this payload serialises as, or null to defer to the default `application/json`. */
    public function mediaTypeFor(DType $payload): ?string;
}
