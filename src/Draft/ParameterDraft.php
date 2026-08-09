<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\NodeExtension;
use Docuccino\Core\Document\Parameter;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Support\Hydrate;

/**
 * A mutable OAS parameter builder, keyed in its parent operation by `(in, name)`. `in` and
 * `name` are fixed at construction (they form the identity); every other field is guarded.
 */
final class ParameterDraft
{
    private readonly PatchGuard $guard;

    private readonly SchemaDraft $schema;

    /**
     * Additive semantic facts an integration records under this parameter's `x-docuccino.facts`.
     * Facts stay stable whatever the representation, and nothing contests them, so they skip the
     * guard.
     *
     * @var array<string, mixed>
     */
    private array $facts = [];

    private ?string $id = null;

    public function __construct(
        public readonly string $in,
        public readonly string $name,
    ) {
        $this->guard = new PatchGuard;
        $this->schema = new SchemaDraft;
    }

    public function setDescription(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('description', $value, $by);
    }

    public function setRequired(?bool $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('required', $value, $by);
    }

    public function setDeprecated(?bool $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('deprecated', $value, $by);
    }

    public function set(string $field, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($field, $value, $by);
    }

    public function schema(): SchemaDraft
    {
        return $this->schema;
    }

    /** Record an additive `x-docuccino` semantic fact on this parameter (e.g. a route-binding note). */
    public function setDocuccinoFact(string $key, mixed $value): void
    {
        $this->facts[$key] = $value;
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
     * The `in:name` collection key. This is the one place that convention lives;
     * {@see OperationDraft} calls through here.
     */
    public static function keyFor(string $in, string $name): string
    {
        return $in.':'.$name;
    }

    public function key(): string
    {
        return self::keyFor($this->in, $this->name);
    }

    public function freeze(): Parameter
    {
        $resolved = $this->guard->resolved();

        $description = Hydrate::stringOrNull($resolved['description'] ?? null);
        $required = Hydrate::boolOrNull($resolved['required'] ?? null);
        $deprecated = Hydrate::boolOrNull($resolved['deprecated'] ?? null);

        unset($resolved['description'], $resolved['required'], $resolved['deprecated']);

        $schema = $this->schema->freeze();
        $schemaOrNull = $schema->toArray() === [] ? null : $schema;

        $docuccino = new NodeExtension(
            id: $this->id,
            provenance: $this->guard->provenance(),
            rest: $this->facts === [] ? [] : ['facts' => $this->facts],
        );

        return new Parameter(
            name: $this->name,
            in: $this->in,
            description: $description,
            required: $required,
            deprecated: $deprecated,
            schema: $schemaOrNull,
            docuccino: $docuccino->isEmpty() ? null : $docuccino,
            rest: $resolved,
        );
    }
}
