<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * An engine that can say it is a stand-in for one which failed to boot ({@see TypeEngineBuilder}).
 * The failure travels on the ENGINE rather than on what built it, because a host may defer the build
 * to the first question a route asks — by then the caller has long taken delivery, and the engine
 * object is the only thing both sides still hold.
 *
 * The message is the underlying tool's own, so it may name machine paths: relativise it before it
 * reaches a document.
 */
interface ReportsBootFailure
{
    /** The error that stopped the engine booting, or null when nothing failed. */
    public function bootFailure(): ?string;
}
