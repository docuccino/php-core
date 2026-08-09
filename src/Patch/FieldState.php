<?php

declare(strict_types=1);

namespace Docuccino\Core\Patch;

use Docuccino\Core\Provenance\OverrodeEntry;

/**
 * The mutable per-field record inside a {@see PatchGuard}: the current winning contribution
 * and value, plus every value it has displaced (accumulated in override order).
 *
 * @internal
 */
final class FieldState
{
    /**
     * @param  list<OverrodeEntry>  $overrode
     */
    public function __construct(
        public Contribution $winner,
        public mixed $value,
        public array $overrode = [],
    ) {}
}
