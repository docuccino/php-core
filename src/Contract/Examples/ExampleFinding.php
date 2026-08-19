<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Examples;

use Docuccino\Core\Contract\Violation;

/** One documented example that does not satisfy the schema it sits beside. */
final readonly class ExampleFinding
{
    /**
     * @param  string  $pointer  where the example lives in the document
     * @param  string  $label  how a reader would name it: `GET /api/invoices → 200 application/json`
     * @param  list<Violation>  $violations
     */
    public function __construct(
        public string $pointer,
        public string $label,
        public array $violations,
    ) {}
}
