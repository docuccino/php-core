<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Document\Parameter;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\Hydrate;

/**
 * A document's reusable `components` buckets, used to read a `$ref`ing node back as the thing it points
 * at. The differ's one resolver: every position OAS lets a Reference Object stand in — a path item, a
 * request body, a response, a parameter, a security scheme — is read through here before anything is
 * compared.
 *
 * Resolving both sides is what keeps hoisting invisible to the diff: an inline body or parameter that
 * becomes a `$ref` (or moves between component names) compares thing-to-thing and reports nothing, while
 * an edit to a shared one reports against every operation using it.
 *
 * The CONTRACT comes from the component, and OAS says so: `summary` and `description` are the only members
 * a Reference Object may write beside its pointer, they override the component's, and every other sibling
 * is ignored. So a `required: false` written next to a `$ref` at a component that says `required: true`
 * describes nothing, and honouring it reported a parameter becoming optional, or required, for a contract
 * that had not moved. All four resolvers merge that one way — the component's members, with a `summary` or
 * a `description` from the referring node written over them. The identity is the exception, and not a
 * member of the contract: it names the USE rather than the thing the diff pairs on.
 *
 * For a parameter it is also what makes the comparison possible at all: a Reference Object states neither
 * `name` nor `in`, which is how a parameter is told from its neighbours, so unresolved they are
 * indistinguishable.
 *
 * @internal
 */
final readonly class ComponentRefs
{
    /**
     * The members OAS lets a Reference Object override its target with. Everything else beside a `$ref` is
     * ignored, so the target's stands.
     *
     * @var array<string, true>
     */
    private const array OVERRIDES = ['summary' => true, 'description' => true];

    /**
     * @param  array<string, ResponseObject>  $responses
     * @param  array<string, Parameter>  $parameters
     * @param  array<string, PathItem>  $pathItems
     * @param  array<string, array<string, mixed>>  $requestBodies
     * @param  array<string, array<string, mixed>>  $securitySchemes
     */
    private function __construct(
        private array $responses,
        private array $parameters,
        private array $pathItems,
        private array $requestBodies,
        private array $securitySchemes,
    ) {}

    public static function of(UirDocument $document): self
    {
        $rest = $document->components->rest ?? [];

        return new self(
            $document->components->responses ?? [],
            $document->components->parameters ?? [],
            Hydrate::mapOf($rest['pathItems'] ?? null, PathItem::fromArray(...)),
            Hydrate::mapOfArrays($rest['requestBodies'] ?? null),
            Hydrate::mapOfArrays($rest['securitySchemes'] ?? null),
        );
    }

    /**
     * One hop, so a component that is itself a `$ref` stays marked unresolved rather than silently
     * flattening to nothing.
     */
    public function resolveResponse(ResponseObject $response): ResponseObject
    {
        $name = self::componentName($response->ref, 'responses');
        $target = $name === null ? null : ($this->responses[$name] ?? null);

        if ($target === null) {
            return $response;
        }

        return new ResponseObject(
            ref: $target->ref,
            description: $response->description ?? $target->description,
            headers: $target->headers,
            content: $target->content,
            docuccino: $response->docuccino,
            rest: self::overrides($response->rest) + $target->rest,
        );
    }

    /**
     * A parameter's `$ref` lives among its non-modelled members. The referring site rarely carries an
     * identity of its own — a Reference Object is usually nothing but the pointer — so the component's
     * stands in, which is the id both a UIR document and its exported artifact publish for that parameter.
     */
    public function resolveParameter(Parameter $parameter): Parameter
    {
        $ref = $parameter->rest['$ref'] ?? null;
        $name = is_string($ref) ? self::componentName($ref, 'parameters') : null;
        $target = $name === null ? null : ($this->parameters[$name] ?? null);

        if ($target === null) {
            return $parameter;
        }

        return new Parameter(
            name: $target->name,
            in: $target->in,
            description: $parameter->description ?? $target->description,
            required: $target->required,
            deprecated: $target->deprecated,
            schema: $target->schema,
            docuccino: $parameter->docuccino ?? $target->docuccino,
            rest: self::overrides($parameter->rest) + $target->rest,
        );
    }

    /**
     * A whole endpoint lives behind a path item's `$ref`, and {@see PathItem} leaves the pointer among the
     * members it does not model — so read unresolved, the item states no operations at all. That is the one
     * unresolved node the caller cannot be left to compare as written: one side spelling a path with a
     * pointer and the other inline would read as every operation removed, and both sides spelling it that
     * way would compare nothing while reporting no change. So non-resolution is REPORTED here rather than
     * implied, with {@see UnresolvedRef} saying whether the document itself is why, and
     * {@see DocumentDiffer::diffOperations()} decides what that costs.
     *
     * @return array{0: PathItem, 1: UnresolvedRef|null} the item to diff, and the pointer that reached no path item
     */
    public function resolvePathItem(PathItem $item): array
    {
        $ref = $item->rest['$ref'] ?? null;

        if (! is_string($ref) || $ref === '') {
            return [$item, null];
        }

        $name = self::componentName($ref, 'pathItems');

        if ($name === null) {
            return [$item, UnresolvedRef::unopenable($ref)];
        }

        $target = $this->pathItems[$name] ?? null;

        if ($target === null) {
            return [$item, UnresolvedRef::undeclared($ref)];
        }

        // One hop: a target that is itself a Reference Object — a chain, or a cycle back to here — reaches
        // no path item, and this resolver stopping there is not the document being wrong.
        if (isset($target->rest['$ref'])) {
            return [$item, UnresolvedRef::unopenable($ref)];
        }

        $rest = self::overrides($item->rest) + $target->rest;
        unset($rest['$ref']);

        return [new PathItem(
            operations: $target->operations,
            parameters: $target->parameters,
            docuccino: $item->docuccino ?? $target->docuccino,
            rest: $rest,
        ), null];
    }

    /**
     * A request body is the other node a pointer can hide whole: unresolved it states no `content`, and a
     * comparison that sees no media type on either side reports nothing — which is a tightened body
     * shipping as no breaking change. Reported for the same reason a path item's is.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: array<string, mixed>, 1: UnresolvedRef|null} the body to read, and the pointer that reached no request body
     */
    public function resolveRequestBody(array $body): array
    {
        return self::resolveInto($body, 'requestBodies', $this->requestBodies);
    }

    /**
     * `components.securitySchemes` takes a Reference Object too, and a scheme is compared member by member
     * against its opposite number — so a pointer read as a scheme reports `$ref` itself as a member of the
     * contract, and an inline scheme hoisted behind one reads as the way in changing. Nothing reads a
     * scheme whole, so an unfollowable pointer here needs no answer beyond the node as written.
     *
     * @param  array<string, mixed>  $scheme
     * @return array<string, mixed>
     */
    public function resolveSecurityScheme(array $scheme): array
    {
        [$resolved] = self::resolveInto($scheme, 'securitySchemes', $this->securitySchemes);

        return $resolved;
    }

    /**
     * The one body behind the two array-shaped resolvers: a bucket, a node that may point into it, and the
     * merge {@see self::OVERRIDES} describes.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $bucket
     * @return array{0: array<string, mixed>, 1: UnresolvedRef|null}
     */
    private static function resolveInto(array $node, string $section, array $bucket): array
    {
        $ref = $node['$ref'] ?? null;

        if (! is_string($ref) || $ref === '') {
            return [$node, null];
        }

        $name = self::componentName($ref, $section);

        if ($name === null) {
            return [$node, UnresolvedRef::unopenable($ref)];
        }

        $target = $bucket[$name] ?? null;

        if ($target === null) {
            return [$node, UnresolvedRef::undeclared($ref)];
        }

        if (isset($target['$ref'])) {
            return [$node, UnresolvedRef::unopenable($ref)];
        }

        $merged = self::overrides($node) + $target;
        unset($merged['$ref']);

        return [$merged, null];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function overrides(array $node): array
    {
        return array_intersect_key($node, self::OVERRIDES);
    }

    /**
     * The component a local pointer names. The RFC 6901 escapes are not decoration: a component name
     * carrying a `/` or a `~` is spelled `~1`/`~0` in every pointer to it, so a resolver comparing the raw
     * text finds nothing and calls a perfectly resolvable reference dangling. A token with a `/` still in
     * it after that names something INSIDE a component rather than the component, which is not a node this
     * resolver hands back — and not a name this document can be said to have left undeclared either.
     */
    private static function componentName(?string $ref, string $section): ?string
    {
        $prefix = '#/components/'.$section.'/';

        if ($ref === null || ! str_starts_with($ref, $prefix)) {
            return null;
        }

        $token = substr($ref, strlen($prefix));

        if ($token === '' || str_contains($token, '/')) {
            return null;
        }

        return Pointer::unescape($token);
    }
}
