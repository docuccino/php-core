<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;

/**
 * The assembled document array plus any diagnostics raised while merging fragments, hoisting
 * components and applying overlays.
 *
 * @internal
 */
final readonly class AssemblyResult
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<Diagnostic>  $diagnostics
     */
    public function __construct(
        public array $document,
        public array $diagnostics = [],
    ) {}
}
