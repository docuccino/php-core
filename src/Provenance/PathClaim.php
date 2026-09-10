<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * What a reason inside {@see MessagePaths} can establish about a run of text, and an objection can
 * deny. The two are not the same size and the difference decides what a rewrite may remove: the
 * weaker claim covers the run and buys nothing but the right to ask the ladder, the stronger one
 * covers a prefix and is the only thing that authorises removing text on the prefix's own account.
 *
 * @internal
 */
enum PathClaim
{
    /** This run of characters is a filesystem path, so the ladder may be asked what it reduces to. */
    case RunIsAPath;

    /**
     * The text in front of the character an objection is spelled with is a directory this machine was
     * configured from, so removing it is a strip of text the ladder matched rather than a guess at
     * where a path ends. It says nothing about the rest of the run.
     */
    case PrefixIsAMachineWord;
}
