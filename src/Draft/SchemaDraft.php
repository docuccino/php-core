<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\NodeExtension;
use Docuccino\Core\Document\SchemaObject;
use Docuccino\Core\Extensions\Validation\DeepObjectMembers;
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

    /** What producers have stated about individual members' requiredness — see {@see requirements()}. */
    private readonly PatchGuard $requiredMembers;

    /**
     * @var array<string, SchemaDraft>
     */
    private array $properties = [];

    /**
     * Member names a subtraction took off this schema ({@see removeProperty()}).
     *
     * @var list<string>
     */
    private array $removed = [];

    private ?string $id = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $mock = null;

    public function __construct()
    {
        $this->guard = new PatchGuard;
        $this->requiredMembers = new PatchGuard;
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

    /**
     * State whether ONE member belongs in this schema's `required` list ({@see requirements()}).
     *
     * @internal Core-only — an extension does not hold the parent, so it states one through
     * {@see DeepObjectMembers::stateRequired()}.
     */
    public function stateMemberRequired(string $member, bool $required, Contribution $by): PatchResult
    {
        return $this->requiredMembers->apply($member, $required, $by);
    }

    /**
     * {@see removeProperty()}'s own answer, asked without removing anything, so a caller judging a
     * subtraction and the subtraction itself cannot disagree.
     *
     * @internal Core-only — see {@see propertyNames()}.
     */
    public function publishesProperty(string $name): bool
    {
        return in_array($name, $this->propertyNames(), true);
    }

    /**
     * The member names {@see freeze()} will publish: the nested drafts where there are any, else the keys
     * of a `properties` written whole. Both, or a subtraction against a declared shape finds nothing.
     *
     * @internal Core-only — a name is a member of a CONTAINER, so an extension asks
     * {@see DeepObjectMembers} instead.
     *
     * @return list<string>
     */
    public function propertyNames(): array
    {
        $resolved = $this->resolvedField('properties');
        $names = $this->properties !== []
            ? array_keys($this->properties)
            : array_keys(is_array($resolved) ? $resolved : []);

        return array_values(array_diff(array_map(strval(...), $names), $this->removed));
    }

    /**
     * Take one member off this schema, and off the `required` list its PARENT keeps — the only place both
     * are in view. Answers whether the schema published the name. Applied at {@see freeze()} rather than
     * through the guard, so nothing outranks it, as {@see OperationDraft::removeParameter()} already does
     * for a whole parameter (docs/design/defect-classes.md §"A subtraction leaves no evidence").
     *
     * @internal Core-only — see {@see propertyNames()}.
     */
    public function removeProperty(string $name): bool
    {
        $published = $this->publishesProperty($name);

        unset($this->properties[$name]);

        if (! in_array($name, $this->removed, true)) {
            $this->removed[] = $name;
        }

        return $published;
    }

    /**
     * @internal Not part of the frozen extension-author surface — an identity is a function of the
     * assembled document and is stamped on the frozen node, so nothing an extension sees decides one.
     */
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
     * Whether this draft, as it stands, says nothing about the value it describes — every keyword an
     * annotation and no property written. What a producer reads when its claim is about the OUTCOME
     * rather than its own write; an unclassifiable keyword counts as saying something.
     */
    public function saysNothingAboutTheInstance(): bool
    {
        if ($this->properties !== []) {
            return false;
        }

        foreach (array_keys($this->guard->resolved()) as $keyword) {
            if (! SchemaKeywords::saysNothingAboutTheInstance((string) $keyword)) {
                return false;
            }
        }

        return true;
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

        $requirements = $this->requirements();
        $required = array_column($requirements, 0);
        $also = [];

        if ($required === []) {
            unset($data['required']);
        } else {
            $data['required'] = $required;
            // Attributed to the highest layer that put a member on the list: the guard holds no
            // winning write for a keyword assembled here.
            $also['required'] = array_reduce(
                $requirements,
                static fn (?Contribution $best, array $each): ?Contribution => self::higher($best, $each[1]),
            );
        }

        if ($this->properties !== []) {
            $properties = [];
            foreach ($this->properties as $name => $draft) {
                $properties[$name] = $draft->freeze()->toArray();
            }
            $data['properties'] = $properties;
        }

        if ($this->removed !== []) {
            $data = self::withoutProperties($data, $this->removed);
        }

        $except = array_values(array_diff($this->guard->fields(), array_map(strval(...), array_keys($data))));
        $except[] = 'required';

        $docuccino = new NodeExtension(
            id: $this->id,
            provenance: $this->guard->provenance(array_filter($also), $except),
            mock: $this->mock,
        );

        if (! $docuccino->isEmpty()) {
            $data['x-docuccino'] = $docuccino->toArray();
        }

        return new SchemaObject($data);
    }

    /**
     * `$data` with every subtracted member gone from `properties`, the keyword dropped once it is empty.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $removed
     * @return array<string, mixed>
     */
    private static function withoutProperties(array $data, array $removed): array
    {
        $properties = $data['properties'] ?? null;
        if (! is_array($properties)) {
            return $data;
        }

        foreach ($removed as $name) {
            unset($properties[$name]);
        }

        $data['properties'] = $properties;
        if ($properties === []) {
            unset($data['properties']);
        }

        return $data;
    }

    /**
     * The ONE reading of this schema's `required` list: the keyword written whole, patched by the
     * per-member statements ({@see stateMemberRequired()}), minus what a subtraction took off. Per member
     * because the contested unit is the member — the guard arbitrates a FIELD, so a merged list would
     * shadow a lower layer's whole (docs/design/defect-classes.md §"A second reading, narrower than the
     * write it answers for"). Names are cast: PHP normalises the key `"2024"` to an int.
     *
     * @return list<array{0: string, 1: Contribution}>
     */
    private function requirements(): array
    {
        /** @var array<string, int> $at */
        $at = [];
        /** @var array<int, array{0: string, 1: Contribution}> $named */
        $named = [];

        $keyword = $this->guard->contributions()['required'] ?? null;
        if ($keyword !== null && is_array($keyword['value'])) {
            foreach ($keyword['value'] as $each) {
                if (! is_string($each) && ! is_int($each)) {
                    continue;
                }

                $member = (string) $each;
                if (! isset($at[$member])) {
                    $at[$member] = count($named);
                    $named[count($named)] = [$member, $keyword['by']];
                }
            }
        }

        foreach ($this->requiredMembers->contributions() as $key => $write) {
            $member = (string) $key;
            $position = $at[$member] ?? null;

            if ($write['value'] !== true) {
                if ($position !== null) {
                    unset($named[$position], $at[$member]);
                }

                continue;
            }

            // A member already listed keeps its position: restating it must not reorder the list.
            $at[$member] ??= count($named);
            $named[$at[$member]] = [$member, $write['by']];
        }

        return array_values(array_filter(
            $named,
            fn (array $each): bool => ! in_array($each[0], $this->removed, true),
        ));
    }

    /**
     * The highest-ranking contribution behind this schema requiring a member, at any depth, or null.
     * A deepObject container's own requiredness is derived from it ({@see ParameterDraft::freeze()}).
     *
     * @internal Core-only.
     */
    public function memberRequirement(): ?Contribution
    {
        $best = null;

        foreach ($this->requirements() as [, $by]) {
            $best = self::higher($best, $by);
        }

        foreach ($this->properties as $name => $property) {
            if (in_array((string) $name, $this->removed, true)) {
                continue;
            }

            $best = self::higher($best, $property->memberRequirement());
        }

        // A `properties` map written whole, which freeze() publishes only where no draft does.
        $resolved = $this->resolvedField('properties');
        if ($this->properties === [] && is_array($resolved) && self::mapRequiresAMember($resolved, $this->removed)) {
            $best = self::higher($best, $this->guard->contributions()['properties']['by'] ?? null);
        }

        return $best;
    }

    /**
     * Whether a `properties` map written as a keyword requires a member of its own, at any depth.
     *
     * @param  array<array-key, mixed>  $properties
     * @param  list<string>  $removed
     */
    private static function mapRequiresAMember(array $properties, array $removed): bool
    {
        foreach ($properties as $name => $schema) {
            if (! is_array($schema) || in_array((string) $name, $removed, true)) {
                continue;
            }

            $required = $schema['required'] ?? null;
            if (is_array($required) && $required !== []) {
                return true;
            }

            $nested = $schema['properties'] ?? null;
            if (is_array($nested) && self::mapRequiresAMember($nested, [])) {
                return true;
            }
        }

        return false;
    }

    private static function higher(?Contribution $incumbent, ?Contribution $candidate): ?Contribution
    {
        if ($incumbent === null || $candidate === null) {
            return $incumbent ?? $candidate;
        }

        return $candidate->outranks($incumbent) ? $candidate : $incumbent;
    }
}
