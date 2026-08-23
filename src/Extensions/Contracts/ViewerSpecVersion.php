<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * A {@see Viewer} whose pinned build implements a specific OpenAPI minor, so an adapter feeds its
 * spec endpoint that version instead of the newest one the generator emits. Tolerating a newer minor
 * is not implementing it — a build that parses 3.2 by aliasing it to 3.1 silently drops the newer
 * minor's semantics, so it declares '3.1'.
 */
interface ViewerSpecVersion
{
    /** The OpenAPI minor the pinned build implements: '3.0', '3.1' or '3.2'. */
    public function specVersion(): string;
}
