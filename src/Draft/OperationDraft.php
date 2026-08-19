<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\NodeExtension;
use Docuccino\Core\Document\Operation;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Support\Hydrate;

/**
 * The mutable operation builder. Scalar fields go through the guard; parameters merge by
 * `(in, name)` and responses by status, each a nested draft with its own guard — so a targeted
 * patch never wipes inferred siblings. {@see freeze()} produces the immutable {@see Operation},
 * provenance assembled into every node's `x-docuccino`.
 */
final class OperationDraft
{
    private readonly PatchGuard $guard;

    /**
     * @var array<string, ParameterDraft>
     */
    private array $parameters = [];

    /**
     * @var array<string, ResponseDraft>
     */
    private array $responses = [];

    /**
     * Request-body examples per media type ({@see declareRequestBodyExamples()}). The body itself is
     * one guarded field several producers write whole, so examples are merged into it at {@see freeze()}
     * rather than contesting it — an example adds to a body, it never disagrees with one.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $requestExamples = [];

    /** @var array<string, mixed> */
    private array $requestExample = [];

    private ?string $id = null;

    public function __construct()
    {
        $this->guard = new PatchGuard;
    }

    public function setOperationId(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('operationId', $value, $by);
    }

    public function setSummary(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('summary', $value, $by);
    }

    public function setDescription(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('description', $value, $by);
    }

    public function setDeprecated(?bool $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('deprecated', $value, $by);
    }

    /**
     * @param  list<string>|null  $tags
     */
    public function setTags(?array $tags, Contribution $by): PatchResult
    {
        return $this->guard->apply('tags', $tags, $by);
    }

    /**
     * @param  list<array<string, mixed>>|null  $security
     */
    public function setSecurity(?array $security, Contribution $by): PatchResult
    {
        return $this->guard->apply('security', $security, $by);
    }

    public function set(string $field, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($field, $value, $by);
    }

    public function parameter(string $in, string $name): ParameterDraft
    {
        $key = ParameterDraft::keyFor($in, $name);

        return $this->parameters[$key] ??= new ParameterDraft($in, $name);
    }

    public function hasParameter(string $in, string $name): bool
    {
        return isset($this->parameters[ParameterDraft::keyFor($in, $name)]);
    }

    public function removeParameter(string $in, string $name): void
    {
        unset($this->parameters[ParameterDraft::keyFor($in, $name)]);
    }

    public function response(string $status): ResponseDraft
    {
        return $this->responses[$status] ??= new ResponseDraft($status);
    }

    public function hasResponse(string $status): bool
    {
        return isset($this->responses[$status]);
    }

    public function removeResponse(string $status): void
    {
        unset($this->responses[$status]);
    }

    /**
     * Every status this operation has a response draft for, byte-sorted so the answer is a function of
     * the statuses rather than of the order the producers registered them.
     *
     * @return list<string>
     */
    public function responseStatuses(): array
    {
        $statuses = array_map(strval(...), array_keys($this->responses));
        sort($statuses, SORT_STRING);

        return $statuses;
    }

    /**
     * Attach examples to a request-body media type: a map of named Example Objects, a singular value,
     * or both over several calls. They are merged into whatever body wins ({@see $requestExamples}); a
     * media type the body doesn't carry is left alone, and a non-empty map wins over a singular because
     * OAS makes the two members mutually exclusive.
     *
     * @param  array<string, array<string, mixed>>  $named
     */
    public function declareRequestBodyExamples(string $mediaType, array $named, mixed $singular = null): void
    {
        if ($named !== []) {
            $merged = ($this->requestExamples[$mediaType] ?? []) + $named;
            ksort($merged);
            $this->requestExamples[$mediaType] = $merged;
        }

        if ($singular !== null) {
            $this->requestExample[$mediaType] ??= $singular;
        }
    }

    /** The provenance producer of the currently-winning contribution for a field, or null if unset. */
    public function producerFor(string $field): ?string
    {
        return $this->guard->producerFor($field);
    }

    /** The currently-resolved value of a field (Remove sentinels omitted), or null if unset. */
    public function resolvedField(string $field): mixed
    {
        return $this->guard->resolved()[$field] ?? null;
    }

    /**
     * The request body with the declared examples folded into the media types it already carries. A
     * body shaped like nothing this recognises comes back untouched — an example is never worth
     * rewriting a contract for.
     */
    private function withRequestExamples(mixed $body): mixed
    {
        if (($this->requestExamples === [] && $this->requestExample === []) || ! is_array($body)) {
            return $body;
        }

        $content = $body['content'] ?? null;
        if (! is_array($content)) {
            return $body;
        }

        $updated = [];
        foreach ($content as $mediaType => $media) {
            $key = (string) $mediaType;
            $named = $this->requestExamples[$key] ?? [];

            if (is_array($media) && $named !== []) {
                unset($media['example']);
                $media['examples'] = $named;
            } elseif (is_array($media) && array_key_exists($key, $this->requestExample)) {
                $media['example'] = $this->requestExample[$key];
            }

            $updated[$mediaType] = $media;
        }

        $body['content'] = $updated;

        return $body;
    }

    /**
     * @internal Not part of the frozen extension-author surface — it hands back the (also
     * `@internal`) {@see PatchGuard}. Extensions read winning state via {@see producerFor()} /
     * {@see resolvedField()}.
     */
    public function guard(): PatchGuard
    {
        return $this->guard;
    }

    public function assignId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Assigns identities to every child parameter and response draft. The response callback gets the
     * primary media type (`''` when there isn't one, e.g. a `$ref`) and may return null to leave the
     * response id-less.
     *
     * @param  callable(string $in, string $name): ?string  $parameterId
     * @param  callable(string $status, string $primaryMediaType): ?string  $responseId
     */
    public function assignChildIds(callable $parameterId, callable $responseId): void
    {
        foreach ($this->parameters as $draft) {
            $draft->assignId($parameterId($draft->in, $draft->name));
        }

        foreach ($this->responses as $status => $draft) {
            // PHP coerces numeric-string keys like '200' to ints; the callback wants a string.
            $draft->assignId($responseId((string) $status, $draft->primaryMediaType()));
        }
    }

    /**
     * @internal Not part of the frozen extension-author surface — it hands back the (also
     * `@internal`) {@see Operation} document model. Extensions hand drafts back to the pipeline,
     * which freezes them.
     */
    public function freeze(): Operation
    {
        $resolved = $this->guard->resolved();

        $operationId = Hydrate::stringOrNull($resolved['operationId'] ?? null);
        $summary = Hydrate::stringOrNull($resolved['summary'] ?? null);
        $description = Hydrate::stringOrNull($resolved['description'] ?? null);
        $deprecated = Hydrate::boolOrNull($resolved['deprecated'] ?? null);
        $tags = Hydrate::stringList($resolved['tags'] ?? null);
        $security = Hydrate::listOfMaps($resolved['security'] ?? null);

        unset(
            $resolved['operationId'], $resolved['summary'], $resolved['description'],
            $resolved['deprecated'], $resolved['tags'], $resolved['security'],
        );

        if (isset($resolved['requestBody'])) {
            $resolved['requestBody'] = $this->withRequestExamples($resolved['requestBody']);
        }

        $parameters = [];
        foreach ($this->parameters as $draft) {
            $parameters[] = $draft->freeze();
        }

        $responses = [];
        foreach ($this->responses as $status => $draft) {
            $responses[$status] = $draft->freeze();
        }

        $docuccino = new NodeExtension(id: $this->id, provenance: $this->guard->provenance());

        return new Operation(
            operationId: $operationId,
            summary: $summary,
            description: $description,
            tags: $tags,
            deprecated: $deprecated,
            parameters: $parameters,
            responses: $responses,
            security: $security,
            docuccino: $docuccino->isEmpty() ? null : $docuccino,
            rest: $resolved,
        );
    }
}
