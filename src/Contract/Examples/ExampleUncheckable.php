<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Examples;

/** One documented example nothing could check, and why. */
final readonly class ExampleUncheckable
{
    /**
     * @param  string  $pointer  where the example lives in the document
     * @param  string  $schemaPointer  the schema it would have been checked against
     * @param  string  $label  how a reader would name it: `GET /api/invoices → 200 application/json`
     * @param  string  $reason  why — the validator's own words where it refused one, with any machine
     *                          path already relativised
     */
    public function __construct(
        public string $pointer,
        public string $schemaPointer,
        public string $label,
        public string $reason,
    ) {}
}
