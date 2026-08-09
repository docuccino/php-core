<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * A gated seam contributing one segment of the document-level fragment-cache environment digest
 * (design §10): booted-app state that shapes an operation's output but that no route file reflects —
 * auth guards, a paginator's parameter names, the morph map, registered render callbacks, spatie-data
 * globals and so on. Each is a global fact whose change can alter any route, so the aggregate keys
 * the cache once per document rather than per route.
 *
 * Resolved per-document like the other gated chains ({@see ResponseStatusResolver} et al.): an
 * integration contributes only when installed and enabled for this document, so a disabled
 * integration's globals never key a warm fragment. The pipeline reads this chain and never an
 * integration class.
 *
 * Reads must be defensive — an unresolvable fact contributes the empty string rather than failing the
 * build, which keeps the aggregate total and deterministic.
 */
interface EnvironmentDigestContributor
{
    /**
     * A deterministic digest segment of this contributor's output-shaping booted-app state, or the
     * empty string when nothing resolves.
     */
    public function digest(): string;
}
