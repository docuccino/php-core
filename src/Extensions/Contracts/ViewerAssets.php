<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

/**
 * A {@see Viewer} that ships browser assets of its own, so the page it renders can be served without
 * reaching a CDN.
 *
 * The map IS the allow-list: an adapter serves the files it names and nothing else, so no asset name
 * arriving over HTTP can reach a path the viewer did not publish.
 */
interface ViewerAssets
{
    /**
     * Asset name (as it appears in the URL) => absolute path to the file on disk. Names are
     * `[A-Za-z0-9_-]+`; anything else is unreachable through the adapter's asset route.
     *
     * @return array<string, string>
     */
    public function assets(): array;
}
