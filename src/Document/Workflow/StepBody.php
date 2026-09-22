<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

use Docuccino\Core\Support\Hydrate;

/**
 * The request body a workflow step sends: the media type it is sent as, and the payload — literal
 * members, runtime expressions, or a mixture, exactly as the author wrote it.
 *
 * The payload is carried as written rather than checked against the operation's request schema here. A
 * payload is part expression by design, and an expression has no value until the workflow runs, so
 * anything this could say about the shape would be said about a body nobody has built yet.
 *
 * @internal
 */
final readonly class StepBody
{
    public function __construct(
        public string $contentType,
        public mixed $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            contentType: Hydrate::stringOr($data['contentType'] ?? '', ''),
            payload: $data['payload'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['contentType' => $this->contentType, 'payload' => $this->payload];
    }
}
