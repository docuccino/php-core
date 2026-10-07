<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Emit\UirEmitter;

/**
 * `contentHash`: hex SHA-256 over the document's canonical serialization, minus everything that
 * describes the TOOL rather than the API — `x-docuccino.generator` and `x-docuccino.diagnostics` — so
 * tool upgrades and diagnostic churn don't dirty committed diffs. `x-docuccino.document.contentHash`
 * is excluded too — a hash can't be one of its own inputs — which keeps the value recomputable and
 * stable across rewrites.
 *
 * The spec version and the schema URL are in that set because they live under `generator`, which is
 * where they belong: every consumer diffing two artifacts across a spec upgrade would otherwise see
 * every document's hash move and read it as "the API changed". What a spec version ADDS still moves
 * the hash, because that is content — a workflow list appearing is a real difference. The version
 * STRING saying which spec was used is not.
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

        // The hash is of the document as the full export publishes it, so a reader holding a full
        // artifact written with every provenance record can recompute it from the bytes. One levelled
        // to `winners` or `none` has dropped content the hash covered, and cannot.
        return hash('sha256', (new UirEmitter($this->canonicalizer, $this->serializer))->emitArray($document));
    }
}
