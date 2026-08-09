<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\Viewer;

/**
 * The context handed to a {@see Viewer}: which document to
 * render and the negotiated output format. Minimal in Phase 3a (viewer is Phase 5).
 */
final readonly class ViewerContext
{
    public function __construct(
        public DocumentConfig $config,
        public string $format = 'html',
    ) {}
}
