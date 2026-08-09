<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\NodeExtension;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Support\Hydrate;

/**
 * A mutable OAS response builder, keyed in its parent operation by status. Description and
 * `$ref` are guarded; response content merges by media type, each media type owning one
 * {@see SchemaDraft} (design §7 collection-merge).
 */
final class ResponseDraft
{
    private readonly PatchGuard $guard;

    /**
     * @var array<string, SchemaDraft>
     */
    private array $content = [];

    /**
     * Per-media-type example bodies (an OAS media-type `example`, sibling of `schema`). Assembled by a
     * producer from statically-known values only (literals, resolved status members) — never fabricated
     * — and emitted verbatim: the canonicalizer keeps an `example` opaque, so insertion order is the
     * producer's responsibility.
     *
     * @var array<string, mixed>
     */
    private array $examples = [];

    private ?string $id = null;

    public function __construct(
        public readonly string $status,
    ) {
        $this->guard = new PatchGuard;
    }

    public function setDescription(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('description', $value, $by);
    }

    public function setRef(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('$ref', $value, $by);
    }

    public function set(string $field, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($field, $value, $by);
    }

    public function content(string $mediaType): SchemaDraft
    {
        return $this->content[$mediaType] ??= new SchemaDraft;
    }

    /**
     * Attach an example body to a media type. Emitted only when that media type also carries a schema.
     * First writer wins (a later producer does not overwrite an established example) so the result is
     * order-stable regardless of extension evaluation order.
     */
    public function setExample(string $mediaType, mixed $example): void
    {
        $this->examples[$mediaType] ??= $example;
    }

    /** The first registered media type (in insertion order), or `''` when the response has none. */
    public function primaryMediaType(): string
    {
        return array_key_first($this->content) ?? '';
    }

    public function hasContent(string $mediaType): bool
    {
        return isset($this->content[$mediaType]);
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
     * The underlying patch guard.
     *
     * @internal Not part of the frozen extension-author surface: it hands back the {@see PatchGuard}
     * (itself `@internal`). Extensions read winning state through {@see producerFor()} /
     * {@see resolvedField()} instead.
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

    public function freeze(): ResponseObject
    {
        $resolved = $this->guard->resolved();

        $ref = Hydrate::stringOrNull($resolved['$ref'] ?? null);
        $description = Hydrate::stringOrNull($resolved['description'] ?? null);

        $headers = null;
        if (isset($resolved['headers']) && is_array($resolved['headers'])) {
            /** @var array<string, mixed> $headers */
            $headers = $resolved['headers'];
        }

        unset($resolved['$ref'], $resolved['description'], $resolved['headers']);

        $content = null;
        if ($this->content !== []) {
            $content = [];
            foreach ($this->content as $mediaType => $schema) {
                $content[$mediaType] = ['schema' => $schema->freeze()->toArray()];
                if (array_key_exists($mediaType, $this->examples)) {
                    $content[$mediaType]['example'] = $this->examples[$mediaType];
                }
            }
        }

        $docuccino = new NodeExtension(id: $this->id, provenance: $this->guard->provenance());

        return new ResponseObject(
            ref: $ref,
            description: $description,
            headers: $headers,
            content: $content,
            docuccino: $docuccino->isEmpty() ? null : $docuccino,
            rest: $resolved,
        );
    }
}
