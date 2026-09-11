<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Pipeline\IgnoredHeaderAudit;

/**
 * The header declarations OAS says are not declarations, read once for every reader that acts on them.
 *
 * A header PARAMETER named `Accept`, `Content-Type` or `Authorization`, and a response HEADER named
 * `Content-Type`, SHALL be ignored (OAS 3.2 §4.12.2.1 and the Response Object's `headers` field): the
 * fact each of them states is published by `requestBody.content`, by a response's `content`, or by a
 * security scheme, and honouring the declaration as well would send a header contradicting the message
 * around it. Names are compared case-insensitively, as the wire compares them.
 *
 * Held here rather than at each reader because the readers ACT on it and must act alike: an emitter
 * that drops a declaration a checker still enforces makes one build's artifacts disagree about whether
 * the author declared a parameter at all. What is NOT decided here is whether the document publishes
 * the declaration — it does, and {@see IgnoredHeaderAudit} is what tells the author it means nothing.
 *
 * @internal
 */
final class IgnoredHeaders
{
    /** @var list<string> the header-parameter names OAS says are not parameters, lowercased */
    private const array PARAMETERS = ['accept', 'authorization', 'content-type'];

    /** @var list<string> the response-header names OAS says are not headers, lowercased */
    private const array RESPONSE_HEADERS = ['content-type'];

    /** True where a `in: header` parameter of this name contributes nothing, prose included. */
    public static function parameter(string $name): bool
    {
        return in_array(strtolower($name), self::PARAMETERS, true);
    }

    /** True where a response `headers` entry of this name contributes nothing, prose included. */
    public static function responseHeader(string $name): bool
    {
        return in_array(strtolower($name), self::RESPONSE_HEADERS, true);
    }
}
