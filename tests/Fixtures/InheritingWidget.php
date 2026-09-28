<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/** Runs the parent's constructor, so the property it promotes is always assigned. */
final class InheritingWidget extends PromotingWidget {}
