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
