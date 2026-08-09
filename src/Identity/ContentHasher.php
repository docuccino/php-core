<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;

/**
 * `contentHash` (design §1): SHA-256 (hex) over the canonical serialization of the document
 * with `x-docuccino.generator` and `x-docuccino.diagnostics` excluded, so tool upgrades and diagnostic
 * churn never dirty committed diffs. The `x-docuccino.document.contentHash` field is itself excluded —
 * a hash cannot be one of its own inputs, so the value is recomputable and stable across rewrites.
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
