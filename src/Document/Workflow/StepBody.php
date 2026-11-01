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
 * `replacements` are Arazzo's: values set into the payload at a target (a JSON pointer or an XPath) before
 * it is sent, each `{target, value}` as written. A declared body has none.
 */
final readonly class StepBody
{
    /**
     * @param  list<array<string, mixed>>  $replacements
     */
    public function __construct(
        public string $contentType,
        public mixed $payload,
        public array $replacements = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            contentType: Hydrate::stringOr($data['contentType'] ?? '', ''),
            payload: $data['payload'] ?? null,
            replacements: Hydrate::listOfMaps($data['replacements'] ?? null) ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['contentType' => $this->contentType, 'payload' => $this->payload];

        if ($this->replacements !== []) {
            $out['replacements'] = $this->replacements;
        }

        return $out;
    }
}
