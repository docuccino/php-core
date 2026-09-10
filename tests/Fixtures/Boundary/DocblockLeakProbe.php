<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Boundary;

use Docuccino\Core\Inference\LocalWrites;
use Docuccino\Core\Inference\LocalWrites as Concealed;

/**
 * The state the public-API boundary sweep has to REFUSE, written out so the sweep can be run over it:
 * one `@internal` type promised in a native signature, and one promised only in a docblock behind an
 * `array` the reflection half reads as harmless. The aliased import is here so the resolver is exercised
 * on the spelling a name scan cannot follow.
 */
final class DocblockLeakProbe
{
    public function natively(): LocalWrites
    {
        return new LocalWrites;
    }

    /** @return list<Concealed> */
    public function onlyInTheDocblock(): array
    {
        return [];
    }

    /** @return list<string> */
    public function harmless(): array
    {
        return [];
    }
}
