<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * A whole-document post-processor (design §6), run after fragments are assembled and overlays
 * applied but before canonicalisation. Mutates the {@see UirDocumentDraft} in place.
 */
interface DocumentTransformer
{
    public function transform(UirDocumentDraft $document, DocumentContext $context): void;
}
