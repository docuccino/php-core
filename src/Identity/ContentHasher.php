<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;

/**
 * `contentHash`: hex SHA-256 over the document's canonical serialization, minus
 * `x-docuccino.generator` and `x-docuccino.diagnostics`, so tool upgrades and diagnostic churn don't
 * dirty committed diffs. `x-docuccino.document.contentHash` is excluded too — a hash can't be one of
 * its own inputs — which keeps the value recomputable and stable across rewrites.
 *
 * @internal
 */
final readonly class ContentHasher
{
    public function __construct(
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function hash(array $document): string
    {
        if (isset($document['x-docuccino']) && is_array($document['x-docuccino'])) {
            unset($document['x-docuccino']['generator'], $document['x-docuccino']['diagnostics']);

            if (isset($document['x-docuccino']['document']) && is_array($document['x-docuccino']['document'])) {
                unset($document['x-docuccino']['document']['contentHash']);

                if ($document['x-docuccino']['document'] === []) {
                    unset($document['x-docuccino']['document']);
                }
            }

            if ($document['x-docuccino'] === []) {
                unset($document['x-docuccino']);
            }
        }

        $canonical = $this->serializer->serialize($this->canonicalizer->canonicalize($document));

        return hash('sha256', $canonical);
    }
}
