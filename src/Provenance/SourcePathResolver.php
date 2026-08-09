<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * Normalises an absolute source path to a stable, project-root-relative one for provenance
 * `source.file` — a provenance input, never an identity input. The adapter supplies the concrete
 * resolver since it knows the app's base path; core just needs the seam so {@see RouteContext} can
 * mint sources without importing a framework.
 */
interface SourcePathResolver
{
    /** The project-root-relative form of an (absolute) source file path. */
    public function relative(string $file): string;
}
