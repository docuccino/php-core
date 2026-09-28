<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/** Replaces the parent's constructor without calling it, so the property the parent promotes may stay unset. */
final class OverridingWidget extends PromotingWidget
{
    public function __construct() {}
}
