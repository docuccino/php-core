<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\NodeExtension;
use Docuccino\Core\Document\SchemaObject;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Patch\Remove;

/**
 * A mutable JSON Schema builder. Scalar keywords (type, format, enum, required, …) go through the
 * guard; nested object properties merge by name, so a later layer can patch a single property without
 * discarding inferred siblings.
 */
final class SchemaDraft
{
    private readonly PatchGuard $guard;

    /**
     * @var array<string, SchemaDraft>
     */
    private array $properties = [];

    private ?string $id = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $mock = null;

    public function __construct()
    {
        $this->guard = new PatchGuard;
    }

    public function set(string $keyword, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($keyword, $value, $by);
    }

    /**
     * Write a whole schema as ONE declared shape: the keywords it states are applied, and the ones it
     * leaves out are retracted wherever it outranks them. Use this for a converted type — a
     * `#[Response(type: …)]` body, a parameter's declared type, an envelope a trace worked out — and
     * {@see set()} for patching a single keyword.
     *
     * The rule, stated once: **a declaration states its shape whole.** Keywords compose as a
     * conjunction, so a superseded one left standing publishes something nobody declared — a map
     * inference's `additionalProperties` beside a declared closed shape says extra keys are allowed,
     * an inferred `type`/`items` beside a declared `$ref` says the body must satisfy both. Which
     * keywords a shape supersedes is {@see SchemaKeywords}: the ones describing the value's shape go
     * unless restated, the ones refining a type go once the declared shape is not that type, and
     * annotations — a description, an authored example — stay, because they were never about the
     * shape. A write that states no shape at all supersedes nothing.
     *
     * Retraction is a guarded write like any other, so it is bounded by precedence: an overlay-stated
     * keyword survives an attribute-declared shape, and an equal layer can only shadow.
     *
     * @param  array<string, mixed>  $schema
     */
    public function declareShape(array $schema, Contribution $by): void
    {
        if (SchemaKeywords::statesShape($schema)) {
            foreach (array_keys($this->guard->resolved()) as $keyword) {
                if (SchemaKeywords::isSuperseded((string) $keyword, $schema)) {
                    $this->guard->apply((string) $keyword, Remove::value(), $by);
                }
            }

            // Nested property drafts are the other half of `properties`, and freeze() publishes them
            // over the keyword — so a shape that supersedes the keyword has to take them with it, or
            // the declared body would lose to the properties it replaced.
            foreach ($this->properties as $name => $property) {
                if ($property->isSupersededBy($by)) {
                    unset($this->properties[$name]);
                }
            }
        }

        foreach ($schema as $keyword => $value) {
            $this->guard->apply((string) $keyword, $value, $by);
        }
    }

    public function property(string $name): self
    {
        return $this->properties[$name] ??= new self;
    }

    public function hasProperty(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function assignId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $mock
     */
    public function assignMock(?array $mock): self
    {
        $this->mock = $mock;

        return $this;
    }

    /**
     * Take over another schema's keywords and properties, each at the contribution that wrote it — the
     * nested half of {@see ResponseDraft::absorb()}.
     *
     * @internal Core-only; extensions build drafts rather than move them about.
     */
    public function absorb(self $other): void
    {
        foreach ($other->guard->contributions() as $keyword => $write) {
            $this->guard->apply($keyword, $write['value'], $write['by']);
        }

        foreach ($other->properties as $name => $property) {
            $this->property((string) $name)->absorb($property);
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
     * Whether a contribution outranks every keyword written here and in every nested property, so it
     * speaks over the schema as a whole — the nested half of {@see ResponseDraft::isSupersededBy()}.
     *
     * @internal Core-only; the retraction paths ask this, extensions patch keywords.
     */
    public function isSupersededBy(Contribution $by): bool
    {
        if (! $this->guard->outranksAll($by)) {
            return false;
        }

        foreach ($this->properties as $property) {
            if (! $property->isSupersededBy($by)) {
                return false;
            }
        }

        return true;
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

    /**
     * @internal Not part of the frozen extension-author surface — it hands back the (also
     * `@internal`) {@see SchemaObject} document model. Extensions hand drafts back to the pipeline,
     * which freezes them.
     */
    public function freeze(): SchemaObject
    {
        $data = $this->guard->resolved();

        if ($this->properties !== []) {
            $properties = [];
            foreach ($this->properties as $name => $draft) {
                $properties[$name] = $draft->freeze()->toArray();
            }
            $data['properties'] = $properties;
        }

        $docuccino = new NodeExtension(
            id: $this->id,
            provenance: $this->guard->provenance(),
            mock: $this->mock,
        );

        if (! $docuccino->isEmpty()) {
            $data['x-docuccino'] = $docuccino->toArray();
        }

        return new SchemaObject($data);
    }
}
