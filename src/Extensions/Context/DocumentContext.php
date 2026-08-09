<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;

/**
 * The context handed to a {@see DocumentTransformer}: the document config, its resolved identity, and a
 * diagnostics sink so a whole-document transformer like the data-leakage lint can report findings into
 * the build's diagnostics channel.
 */
final readonly class DocumentContext
{
    public function __construct(
        public DocumentConfig $config,
        public string $documentId,
        public DiagnosticCollector $diagnostics = new DiagnosticCollector,
    ) {}

    /** Report a diagnostic from a document transformer into the build's diagnostics channel. */
    public function report(Diagnostic $diagnostic): void
    {
        $this->diagnostics->add($diagnostic);
    }
}
