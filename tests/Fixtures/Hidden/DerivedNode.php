<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures\Hidden;

/**
 * A subclass of a class carrying a class-level `#[Hidden]`. PHP's `getAttributes()` reads the class's
 * OWN attributes and nothing above it, so the deny-list does not reach here — which is why there is no
 * unmatched name to report either.
 */
final class DerivedNode extends BaseNode {}
