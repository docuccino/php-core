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
    /**
     * The OAS range key a redirect is published under when nothing names its code. Public because this
     * is the class that retires it ({@see supersedeStatusRange()}), so whoever else reads the key names
     * it from here rather than spelling it again.
     */
    public const string REDIRECT_RANGE = '3XX';

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

    /**
     * The request body's own `description` ({@see declareRequestBodyDescription()}), merged into the
     * winning body at {@see freeze()} for the reason stated on {@see $requestExamples}.
     */
    private ?string $requestDescription = null;

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
     * Hand the redirect range's findings to a concrete 3xx a producer has just DECLARED — the one full
     * statement of the retraction rule; the other retraction sites point here.
     *
     * `3XX` stands in for ONE status nobody named: a `RedirectResponse` takes any 3xx and the return
     * site says which only sometimes. Once something above the range's own layer names a member of the
     * class, the unknown is known, and publishing both would tell a consumer that any OTHER 3xx may
     * happen too — which is exactly what the declaration denied. The range cannot be narrowed to "301
     * or 302" instead, because a range is not a member set; an endpoint that really answers with
     * several codes declares each of them, and each declaration lands as its own response.
     *
     * Standing in is all the range was for, so it never OWNED what it collected: its fields, bodies and
     * examples move onto the declared status at the contributions that wrote them
     * ({@see ResponseDraft::absorb()}). The `Location` header is the case that matters — a redirect
     * proves it, and it is a fact about the response rather than about the key it was filed under.
     *
     * Only the redirect class is retired. An error range is the other kind of range: `4XX` carrying a
     * problem body says "any 4xx answers like this", which IS a member set, and declaring 404 denies
     * nothing about 409 — so a declared error code adds a response and retires no range. And only a
     * range `$by` outranks whole is retired ({@see ResponseDraft::isSupersededBy()}): one an author
     * published at overlay or config level is their document rather than ours to edit.
     */
    public function supersedeStatusRange(string $status, Contribution $by): void
    {
        if (preg_match('/^3\d\d$/D', $status) !== 1) {
            return;
        }

        $range = $this->responses[self::REDIRECT_RANGE] ?? null;

        if ($range === null || ! $range->isSupersededBy($by)) {
            return;
        }

        unset($this->responses[self::REDIRECT_RANGE]);
        $this->response($status)->absorb($range);
    }

    /**
     * Every parameter this operation has a draft for, as its `in:name` key, byte-sorted for the same
     * reason {@see responseStatuses()} is: what this answers must be a function of the parameters and
     * never of the order the producers wrote them.
     *
     * @return list<string>
     */
    public function parameterKeys(): array
    {
        $keys = array_map(strval(...), array_keys($this->parameters));
        sort($keys, SORT_STRING);

        return $keys;
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

    /**
     * Set the request body's own `description` ({@see $requestDescription}). The FIRST call stands, so a
     * caller reading declarations most-specific-first gets the most specific one.
     */
    public function declareRequestBodyDescription(string $description): void
    {
        $this->requestDescription ??= $description;
    }

    /** The provenance producer of the currently-winning contribution for a field, or null if unset. */
    public function producerFor(string $field): ?string
    {
        return $this->guard->producerFor($field);
    }

    /**
     * Every provenance producer that has written a field — the winner first, then its trail. What a
     * higher layer has since patched still says who built the thing that was patched, so a consumer
     * asking which KIND of producer settled a field is not answered differently by a body that has
     * since picked up an attribute.
     *
     * @return list<string>
     */
    public function producersFor(string $field): array
    {
        return $this->guard->producersFor($field);
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
     * The request body with a declared `description` on it, untouched where the body is shaped like
     * nothing this recognises. Nothing in the build derives request-body prose, so the declaration wins.
     */
    private function withRequestDescription(mixed $body): mixed
    {
        if ($this->requestDescription === null || ! is_array($body)) {
            return $body;
        }

        $body['description'] = $this->requestDescription;

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
            $resolved['requestBody'] = $this->withRequestDescription($resolved['requestBody']);
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
