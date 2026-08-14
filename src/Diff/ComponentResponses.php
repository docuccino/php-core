<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Document\UirDocument;

/**
 * A document's `components.responses`, used to read a `$ref`-ing response back as the body it points at.
 *
 * Resolving both sides is what keeps hoisting invisible to the diff: an inline body that becomes a
 * `$ref` (or moves between component names) compares body-to-body and reports nothing, while an edit to
 * a shared body reports against every operation referencing it. Members stated beside the `$ref` win —
 * the referring response keeps its own identity and provenance.
 *
 * @internal
 */
final readonly class ComponentResponses
{
    private const PREFIX = '#/components/responses/';

    /**
     * @param  array<string, ResponseObject>  $responses
     */
    private function __construct(private array $responses) {}

    public static function of(UirDocument $document): self
    {
        return new self($document->components->responses ?? []);
    }

    /**
     * One hop, so a component that is itself a `$ref` stays marked unresolved rather than silently
     * flattening to nothing.
     */
    public function resolve(ResponseObject $response): ResponseObject
    {
        $name = self::nameOf($response->ref);
        $target = $name === null ? null : ($this->responses[$name] ?? null);

        if ($target === null) {
            return $response;
        }

        return new ResponseObject(
            ref: $target->ref,
            description: $response->description ?? $target->description,
            headers: $response->headers ?? $target->headers,
            content: $response->content ?? $target->content,
            docuccino: $response->docuccino,
            rest: $response->rest + $target->rest,
        );
    }

    private static function nameOf(?string $ref): ?string
    {
        if ($ref === null || ! str_starts_with($ref, self::PREFIX)) {
            return null;
        }

        $name = substr($ref, strlen(self::PREFIX));

        return $name === '' ? null : $name;
    }
}
