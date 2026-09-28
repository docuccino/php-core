<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/** Promotes a nullable property its subclasses may or may not construct. */
class PromotingWidget
{
    public function __construct(
        public ?string $note,
    ) {}
}
