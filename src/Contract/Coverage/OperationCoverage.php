<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

/** One documented operation and whether the suite ever exercised it. */
final readonly class OperationCoverage
{
    public function __construct(
        public ?string $id,
        public string $label,
        public bool $exercised,
    ) {}
}
