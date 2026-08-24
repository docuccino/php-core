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

    /**
     * A lower-or-equal contribution lost to an existing owner. Not a problem report — the ladder
     * working — so no caller reacts to it; what it discarded is kept in the winner's `overrode` trail.
     */
    case Shadowed;

    /** The value was `null` ("not specified"); nothing was written. */
    case NoOp;
}
