<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * A gated seam contributing a segment of the document-level fragment-cache environment digest
 * (design §10, A4): booted-app state that shapes an operation's output but that no route file
 * reflects — the query-builder / json-api-paginate parameter names, the auth guards + Sanctum
 * stateful cookie, the Passport scope catalogue + grants + app.url, the spatie-data wrap / name-
 * mapping / date-format globals, the registered render-callback set, the polymorphic morph map, and
 * the `RateLimiter::for` registration set. Each is a global fact whose change can alter any route's
 * fragment, so the aggregate keys the cache at the document level rather than per route.
 *
 * Resolved per-document like the other gated chains ({@see ResponseStatusResolver} et al.): an
 * integration contributes its contributor only when installed AND enabled-for-this-document, so a
 * DISABLED integration's globals never key the warm-fragment cache — and the pipeline reads only
 * this chain, never an integration class (arch rule: Pipeline never reaches into Integrations).
 *
 * Every read must be defensive: an unresolvable fact contributes the empty string rather than
 * failing the build, keeping the aggregate digest total and deterministic.
 */
interface EnvironmentDigestContributor
{
    /**
     * A deterministic digest segment of this contributor's output-shaping booted-app state, or the
     * empty string when nothing resolves.
     */
    public function digest(): string;
}
