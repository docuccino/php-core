<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * Normalises an absolute source file path to a stable, project-root-relative one for provenance
 * `source.file` (design §4 — a provenance input, never an identity input). Framework-agnostic: the
 * adapter supplies the concrete resolver (it knows the app's base path); core only needs the seam
 * so {@see RouteContext} can mint sources without importing the
 * framework.
 */
interface SourcePathResolver
{
    /** The project-root-relative form of an (absolute) source file path. */
    public function relative(string $file): string;
}
