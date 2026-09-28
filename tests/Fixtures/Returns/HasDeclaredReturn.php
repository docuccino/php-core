<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Returns;

use Docuccino\Core\Tests\Fixtures\SampleStatus as Imported;

/** A trait whose method's `@return` names a class through the TRAIT file's own import. */
trait HasDeclaredReturn
{
    /** @return Imported */
    public function fromTrait(): mixed
    {
        return null;
    }
}
