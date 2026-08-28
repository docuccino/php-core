<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

/**
 * One documented response of one operation, and whether the suite ever exercised it.
 *
 * A response documented with no content and a `default` response are both promises a client can be
 * handed, so both count as one of these. A null status is the operation that documents no responses at
 * all: it promises nothing a status could name, so it carries one row saying whether it was reached.
 */
final readonly class ResponseCoverage
{
    /**
     * @param  string|null  $status  the documented response key — `200`, `4XX`, `default` — or null
     *                               where the operation documents no responses at all
     */
    public function __construct(
        public ?string $status,
        public bool $exercised,
    ) {}
}
