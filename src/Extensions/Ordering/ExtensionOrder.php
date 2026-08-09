<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Ordering;

use Attribute;

/**
 * Declares an extension's ordering (design §6). `priority` breaks ties (higher runs earlier);
 * `before`/`after` name other extension classes to impose hard ordering edges, topologically
 * sorted by {@see ExtensionSorter}. A cycle is a build error, not a silent reordering.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ExtensionOrder
{
    /**
     * @param  list<class-string>  $before  classes this extension must run before
     * @param  list<class-string>  $after  classes this extension must run after
     */
    public function __construct(
        public int $priority = 0,
        public array $before = [],
        public array $after = [],
    ) {}
}
