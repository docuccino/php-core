<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * What an {@see ErrorResponseFinalizer} does to the response the mapper chain rendered for one throw.
 */
enum Finalization
{
    /** Sent as rendered. */
    case Keeps;

    /** Never sent: what the finalizer builds goes instead, and the rendered response is withdrawn. */
    case Replaces;

    /** Sent on some requests and not others: what the finalizer builds is published beside it. */
    case Extends;
}
