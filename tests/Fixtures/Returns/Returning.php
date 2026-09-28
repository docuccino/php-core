<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Returns;

use Docuccino\Core\Tests\Fixtures\SampleStatus as Status;

/** Methods whose docblocks declare what they return, in each spelling DeclaredReturnType reads. */
final class Returning
{
    /** @return list<Status> the statuses, named through an aliased import */
    public function aliased(): array
    {
        return [];
    }

    /**
     * @return array<mixed>
     *
     * @phpstan-return Holder<Status|Sibling, int>
     */
    public function prefixed(): array
    {
        return [];
    }

    public function undeclared(): int
    {
        return 1;
    }

    /** Prose, and no tag. */
    public function prose(): int
    {
        return 1;
    }
}
