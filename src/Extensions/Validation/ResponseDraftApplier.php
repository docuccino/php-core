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
 * schema keyword are merged under the given producer's provenance. Its input is a core value object
 * (a ResponseDraft), not framework code — merging a response into an operation is generic OAS
 * assembly, so it lives in core; the error-response extension and the laravel-actions authorize
 * extension each build a draft differently, then converge on this one applier.
 *
 * The mapper's own draft froze in its provenance under `x-docuccino`; the applier drops that key so
 * the target schema keeps only its keywords and the merge records fresh provenance for `$producer`.
 */
final class ResponseDraftApplier
{
    public function apply(OperationDraft $operation, ResponseDraft $draft, string $producer, ?Source $source = null): void
    {
        $frozen = $draft->freeze();
        $response = $operation->response($draft->status);
        $contribution = Contribution::forProducer($producer, $source);

        // A mapper that references a shared response component (e.g. the Problem Details preset's
        // reusable `#/components/responses/Problem*`) freezes as a `$ref`; the operation's status entry
        // becomes that reference rather than an inline body (design §6 / §11 worked example).
        if ($frozen->ref !== null) {
            $response->setRef($frozen->ref, $contribution);

            return;
        }

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

            // Carry the media-type example (a sibling of `schema`, assembled by the mapper from
            // statically-known values) across the merge; first writer wins in the target.
            if (is_array($media) && array_key_exists('example', $media)) {
                $response->setExample((string) $mediaType, $media['example']);
            }
        }
    }
}
