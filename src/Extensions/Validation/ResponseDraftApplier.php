<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Provenance\Source;

/**
 * Applies a frozen {@see ResponseDraft} to an operation the one way every response-producing source
 * shares: a `$ref` draft becomes the status entry's reference, otherwise the description and each
 * schema keyword merge under the producer's provenance. Merging a response into an operation is
 * generic OAS assembly, so it lives in core; the error-response and laravel-actions authorize
 * extensions each build a draft differently and then converge here.
 *
 * The incoming draft froze its own provenance under `x-docuccino`; that key is dropped so the target
 * keeps only real keywords and the merge records fresh provenance for `$producer`.
 */
final class ResponseDraftApplier
{
    public function apply(OperationDraft $operation, ResponseDraft $draft, string $producer, ?Source $source = null): void
    {
        $frozen = $draft->freeze();
        $response = $operation->response($draft->status);
        $contribution = Contribution::forProducer($producer, $source);

        // A mapper referencing a shared response component (the Problem Details preset's reusable
        // `#/components/responses/Problem*`) freezes as a `$ref` — so the status entry becomes that
        // reference rather than an inline body.
        if ($frozen->ref !== null) {
            $response->setRef($frozen->ref, $contribution);

            return;
        }

        // The name the producer declared for this body, carried across the merge under the producer's own
        // contribution — the target response is where the hoist will read it. Whether it is the status
        // default travels with it, or the merge would turn a derived name into a chosen one.
        $response->claimComponentName($draft->componentClaim(), $contribution, $draft->componentClaimIsStatusDefault());

        if ($frozen->description !== null) {
            $response->setDescription($frozen->description, $contribution);
        }

        foreach ($frozen->content ?? [] as $mediaType => $media) {
            $schema = is_array($media) && is_array($media['schema'] ?? null) ? $media['schema'] : [];
            foreach ($schema as $keyword => $value) {
                if ($keyword === 'x-docuccino') {
                    continue;
                }
                $response->content((string) $mediaType)->set((string) $keyword, $value, $contribution);
            }

            // Carry the media-type example (a sibling of `schema`) across the merge; first writer wins.
            if (is_array($media) && array_key_exists('example', $media)) {
                $response->setExample((string) $mediaType, $media['example']);
            }
        }
    }
}
