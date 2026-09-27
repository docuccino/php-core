<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Tagged;

/** Implements Shape without the seal naming it — the seal is the author's word, not a scan. */
final readonly class Polygon implements Shape
{
    public function __construct(public int $sides) {}
}
