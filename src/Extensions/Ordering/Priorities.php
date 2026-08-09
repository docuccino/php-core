<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Ordering;

/**
 * Named priority anchors for {@see ExtensionOrder} (design §6). Higher runs earlier. Built-ins
 * publish their positions relative to these so third-party extensions can slot in with a plain
 * integer or a `before`/`after` edge.
 */
final class Priorities
{
    public const int FIRST = 1000;

    public const int EARLY = 100;

    public const int DEFAULT = 0;

    public const int LATE = -100;

    public const int LAST = -1000;
}
