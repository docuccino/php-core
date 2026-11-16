<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\StrandedComponents;
use Docuccino\Core\Emit\UirEmitter;

/**
 * One side of a diff read as its contract: no provenance, and none of the components only provenance
 * reached. A full UIR keeps a component the trail of an overridden value names, and every other artifact
 * drops it, so a fresh build diffed against a committed export would otherwise report that component
 * added — and an edit to it breaking — when no consumer can see it.
 *
 * @internal
 */
final class PublishedSide
{
    public static function of(UirDocument $document): UirDocument
    {
        $array = $document->toArray();
        $levelled = (new UirEmitter)->levelled($array, ProvenanceLevel::None);

        // Nothing to strip leaves the document as it was read, with no round trip through the model.
        if ($levelled === $array) {
            return $document;
        }

        return UirDocument::fromArray(StrandedComponents::drop($array, $levelled));
    }
}
