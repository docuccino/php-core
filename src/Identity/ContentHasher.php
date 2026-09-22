<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;

/**
 * `contentHash`: hex SHA-256 over the document's canonical serialization, minus everything that
 * describes the TOOL rather than the API — `x-docuccino.generator`, `x-docuccino.diagnostics`, and the
 * `$schema`/`uir` pair naming the spec version the document is written to — so tool upgrades and
 * diagnostic churn don't dirty committed diffs. `x-docuccino.document.contentHash` is excluded too — a
 * hash can't be one of its own inputs — which keeps the value recomputable and stable across rewrites.
 *
 * The spec version belongs in that set for exactly the reason `generator` does, and was left out of it
 * until a UIR minor actually shipped: every consumer diffing two artifacts across the upgrade would
 * have seen every document's hash move and read it as "the API changed". What a spec minor ADDS still
 * moves the hash, because that is content — a workflow list appearing is a real difference. The
 * version STRING saying which spec was used is not.
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
        unset($document['$schema'], $document['uir']);

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
