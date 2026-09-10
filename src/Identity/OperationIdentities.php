<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

use Docuccino\Core\Document\Operation;
use Docuccino\Core\Document\Parameter;

/**
 * Stamps a frozen operation's identity tree — its own id, and the parameter and response ids derived
 * from it. The one site that mints them, and it runs on the way OUT of the fragment cache rather than
 * on the way in: a stored fragment then carries no identity at all, which is what lets one entry
 * serve every document whose shaping config matches instead of one entry per document.
 *
 * Cold and warm come through here alike, so a warm build's ids are the cold build's by construction
 * rather than by two sites agreeing.
 *
 * @internal
 */
final readonly class OperationIdentities
{
    public function __construct(
        private IdentityGenerator $identity = new IdentityGenerator,
    ) {}

    /**
     * The same operation with `$operationId` on it and every child id re-derived from that.
     *
     * A parameter is keyed by `(in, name)` and a response by `(status, primary media type)`, which are
     * exactly the tuples the identity spec names — and both are fixed at the draft's construction, so
     * reading them off the frozen node says what reading them off the draft said.
     */
    public function stamp(Operation $operation, string $operationId): Operation
    {
        $parameters = [];
        foreach ($operation->parameters as $parameter) {
            $parameters[] = $parameter->withIdentity(
                $this->identity->parameterId($operationId, $parameter->in ?? '', $parameter->name ?? ''),
            );
        }

        $responses = [];
        foreach ($operation->responses as $status => $response) {
            // A response that states its shape elsewhere — a `$ref`, a bare description — names no
            // media type, and it is left id-less rather than given one minted from an empty string,
            // which every such response on the operation would share.
            $mediaType = (string) (array_key_first($response->content ?? []) ?? '');

            $responses[(string) $status] = $response->withIdentity(
                $mediaType === '' ? null : $this->identity->responseId($operationId, (string) $status, $mediaType),
            );
        }

        return $operation->withIdentity($operationId)->withChildren($parameters, $responses);
    }
}
