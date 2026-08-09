<?php

declare(strict_types=1);

namespace Docuccino\Core\Patch;

/**
 * The outcome of a single guarded write.
 */
enum PatchResult
{
    /** The write won and now owns the field (possibly displacing a lower contribution). */
    case Accepted;

    /** A lower-or-equal contribution lost to an existing owner; caller surfaces an info diagnostic. */
    case Shadowed;

    /** The value was `null` ("not specified"); nothing was written. */
    case NoOp;
}
