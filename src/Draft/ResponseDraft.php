<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\NodeExtension;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Support\Hydrate;

/**
 * A mutable OAS response builder, keyed in its parent operation by status. Description and `$ref`
 * are guarded; content merges by media type, each media type owning one {@see SchemaDraft}.
 * Content under a bodyless status is dropped ({@see BODYLESS_STATUS}).
 */
final class ResponseDraft
{
    /**
     * Statuses RFC 9110 forbids content on: 1xx, 204, 205 and 304, plus the OAS `1XX` range key. Every
     * producer registers response content here, so enforcing it once at the write means none of them
     * can document a body the wire cannot carry — inference folding `response()->json(null, 204)` to a
     * `null` payload, an attribute or an integration naming a bodyless status directly. `$/D` so a
     * trailing newline can't sneak past the anchor.
     */
    private const BODYLESS_STATUS = '/^(1\d\d|1XX|204|205|304)$/D';

    /**
     * The guarded field {@see claimComponentName()} writes to, and the `x-docuccino.facts` member it
     * freezes into so the hoist can read it back off the finished document. Public because it is that
     * wire contract: whoever reads the fact back names it from here rather than spelling it again.
     */
    public const COMPONENT = 'component';

    /**
     * Frozen beside {@see COMPONENT} when the standing claim names the WHOLE response — every
     * representation the status answers with — rather than the one body its claimer built. Public for the
     * same reason: the shared-error hoist reads it back off the finished document.
     */
    public const COMPONENT_NAMES_RESPONSE = 'componentNamesResponse';

    private readonly PatchGuard $guard;

    /**
     * @var array<string, SchemaDraft>
     */
    private array $content = [];

    /**
     * The detached drafts {@see content()} hands out under a bodyless status, kept only so a media type
     * still maps to one draft per response rather than a fresh one per call.
     *
     * @var array<string, SchemaDraft>
     */
    private array $discarded = [];

    /**
     * Per-media-type example bodies (the OAS media-type `example`, sibling of `schema`). Producers
     * build these from statically-known values only, never fabricated, and they're emitted verbatim
     * — the canonicalizer treats an `example` as opaque, so insertion order is the producer's job.
     *
     * @var array<string, mixed>
     */
    private array $examples = [];

    /**
     * The named Example Objects an author declared per media type ({@see declareExamples()}), kept
     * sorted by name so adding one never moves another.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $declaredExamples = [];

    /**
     * The named Example Objects a PRODUCER illustrated a media type with ({@see illustrateExamples()}),
     * kept sorted by name for the same reason. Separate from {@see $declaredExamples} because a
     * declaration wins a name they both carry, and separate from {@see $examples} because a set of
     * named illustrations is what OpenAPI spells `examples` rather than `example`.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $illustratedExamples = [];

    /**
     * The singular example an author declared per media type. Separate from {@see $examples} because
     * a declaration outranks an illustration: this is someone saying what the body looks like, that is
     * a producer showing what it worked out.
     *
     * @var array<string, mixed>
     */
    private array $declaredExample = [];

    private ?string $id = null;

    /** Tracks the winning {@see claimComponentName()} write, so it turns over with the name it belongs to. */
    private bool $componentIsStatusDefault = false;

    /** The other half of that write ({@see COMPONENT_NAMES_RESPONSE}), turning over with it. */
    private bool $componentNamesResponse = false;

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

    /**
     * Declare the component name this error response is published under when it hoists — for a producer
     * that speaks for ONE kind of error and can name it better than `Error<status>`, which is what a
     * generated client's type ends up called. Guarded like any other field, so two producers naming one
     * response settle by precedence; two different BODIES claiming one name is a contest the hoist
     * settles, not this.
     *
     * A name no component key could carry ({@see ComponentNames::isLegal()}) is read as no declaration
     * at all and answers `NoOp`, the same as `null`. Enforced at the write like {@see BODYLESS_STATUS}
     * above it, so a name a `$ref` cannot point at never reaches the document — whether or not the
     * shared-error hoist, which is the only thing that would have refused it, is switched on.
     *
     * Both flags are statements only the WRITER can make, which is why they travel with the write rather
     * than being computed back off the finished response. `$isStatusDefault`: the name is the one derived
     * from the status, not one anything chose — a reader cannot tell a deliberate
     * `#[ErrorComponent("NotFound")]` on a 404 from the default it happens to spell. `$namesResponse`:
     * the name describes every representation the status answers with, which is what lets the hoist take
     * it to a response stating several ({@see COMPONENT_NAMES_RESPONSE}, and the design doc's
     * "Shared error components" for why a producer can never say it).
     */
    public function claimComponentName(?string $name, Contribution $by, bool $isStatusDefault = false, bool $namesResponse = false): PatchResult
    {
        $result = $this->guard->apply(
            self::COMPONENT,
            $name !== null && ComponentNames::isLegal($name) ? $name : null,
            $by,
        );

        if ($result === PatchResult::Accepted) {
            $this->componentIsStatusDefault = $isStatusDefault;
            $this->componentNamesResponse = $namesResponse;
        }

        return $result;
    }

    /** The component name a producer declared for this response, or null when none did. */
    public function componentClaim(): ?string
    {
        return Hydrate::stringOrNull($this->guard->resolved()[self::COMPONENT] ?? null);
    }

    /** Whether the standing claim is a status default rather than a name something chose. */
    public function componentClaimIsStatusDefault(): bool
    {
        return $this->componentIsStatusDefault;
    }

    /** Whether the standing claim names the whole response ({@see COMPONENT_NAMES_RESPONSE}). */
    public function componentClaimNamesResponse(): bool
    {
        return $this->componentNamesResponse;
    }

    public function content(string $mediaType): SchemaDraft
    {
        // A bodyless status hands back a detached draft: callers write into it as usual, but nothing
        // survives to the frozen response — including its media type, so the response stays id-less
        // exactly as an always-empty `noContent()` 204 did.
        if ($this->isBodyless()) {
            return $this->discarded[$mediaType] ??= new SchemaDraft;
        }

        return $this->content[$mediaType] ??= new SchemaDraft;
    }

    /**
     * Take over another response's facts, each at the contribution that wrote it — how
     * {@see OperationDraft::supersedeStatusRange()} hands a retired range's findings to the status that
     * retired it. Every field, body and example arrives as if its original producer had written it here,
     * so this response keeps whatever it already states at a higher layer and inherits the rest with its
     * provenance intact. Content aimed at a bodyless status is dropped at the write like any other
     * ({@see BODYLESS_STATUS}).
     *
     * @internal Core-only; extensions build drafts rather than move them about.
     */
    public function absorb(self $other): void
    {
        foreach ($other->guard->contributions() as $field => $write) {
            if ($field === self::COMPONENT) {
                $this->claimComponentName(Hydrate::stringOrNull($write['value']), $write['by'], $other->componentIsStatusDefault, $other->componentNamesResponse);

                continue;
            }

            $this->guard->apply($field, $write['value'], $write['by']);
        }

        foreach ($other->content as $mediaType => $draft) {
            $this->content((string) $mediaType)->absorb($draft);
        }

        foreach ($other->declaredExamples as $mediaType => $named) {
            $this->declareExamples((string) $mediaType, $named);
        }

        foreach ($other->declaredExample as $mediaType => $example) {
            $this->declareExamples((string) $mediaType, [], $example);
        }

        foreach ($other->illustratedExamples as $mediaType => $named) {
            $this->illustrateExamples((string) $mediaType, $named);
        }

        foreach ($other->examples as $mediaType => $example) {
            $this->setExample((string) $mediaType, $example);
        }
    }

    /**
     * Retract the media RANGE body a named media type supersedes — the response half of the retraction
     * rule stated in full at {@see OperationDraft::supersedeStatusRange()}. The any-media-type range is
     * what a producer documents a stream it could not read under; it also captures
     * {@see primaryMediaType()}, and with it the response's identity and whatever an unnamed example
     * illustrates, so leaving it beside a declared type erases the declaration.
     *
     * Unlike the status range there is nothing to reparent: the range body IS the shape being replaced,
     * and a producer's guess at a stream's bytes says nothing about the type just named.
     *
     * A range covers a concrete type when its type half matches — the any-media-type range covers
     * everything, `text/` + `*` covers `text/csv`.
     */
    public function supersedeMediaRange(string $mediaType, Contribution $by): void
    {
        if (str_contains($mediaType, '*')) {
            return;
        }

        foreach ($this->content as $key => $draft) {
            if (self::covers((string) $key, $mediaType) && $draft->isSupersededBy($by)) {
                unset($this->content[$key]);
            }
        }
    }

    /**
     * Whether a content key is a media RANGE that covers a concrete media type. Only the two shapes
     * OpenAPI defines are ranges: the any-media-type key, and a type half followed by a wildcard.
     * Anything else — a concrete type, a key carrying parameters — covers nothing but itself, so it is
     * never retired by this.
     */
    private static function covers(string $key, string $mediaType): bool
    {
        if ($key === '*/*') {
            return true;
        }

        if (! str_ends_with($key, '/*')) {
            return false;
        }

        return str_starts_with($mediaType, substr($key, 0, -1));
    }

    /**
     * Whether HTTP forbids this response a body ({@see BODYLESS_STATUS}) — ask before converting a payload,
     * since a component hoisted for content that then gets dropped is left orphaned.
     */
    public function isBodyless(): bool
    {
        return preg_match(self::BODYLESS_STATUS, $this->status) === 1;
    }

    /**
     * Attach an example body to a media type; only emitted if that media type also carries a schema.
     * First writer wins, so extension evaluation order can't change the result.
     */
    public function setExample(string $mediaType, mixed $example): void
    {
        $this->examples[$mediaType] ??= $example;
    }

    /**
     * Attach named Example Objects a producer worked out for a media type — a recording of a scenario a
     * test named, say. First writer of a name wins, the map stays name-sorted, and every one of them
     * steps aside for a declaration of the same name ({@see freeze()}).
     *
     * @param  array<string, array<string, mixed>>  $named
     */
    public function illustrateExamples(string $mediaType, array $named): void
    {
        if ($named === []) {
            return;
        }

        $merged = ($this->illustratedExamples[$mediaType] ?? []) + $named;
        ksort($merged);
        $this->illustratedExamples[$mediaType] = $merged;
    }

    /**
     * Attach the examples an author declared for a media type: a map of named Example Objects, a
     * singular value, or both over several calls. Either outranks whatever {@see setExample()}
     * illustrated the media type with, and OAS makes `example` and `examples` mutually exclusive, so a
     * non-empty map wins over a singular. First declaration of a name wins; the map stays name-sorted.
     *
     * @param  array<string, array<string, mixed>>  $named
     */
    public function declareExamples(string $mediaType, array $named, mixed $singular = null): void
    {
        if ($named !== []) {
            $merged = ($this->declaredExamples[$mediaType] ?? []) + $named;
            ksort($merged);
            $this->declaredExamples[$mediaType] = $merged;
        }

        if ($singular !== null) {
            $this->declaredExample[$mediaType] ??= $singular;
        }
    }

    /** The first media type registered, or `''` when the response has none. */
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

    /**
     * Whether a contribution outranks everything written here — every guarded field AND every body,
     * down to the nested keywords, since retiring the node moves all of it. The example bags carry no
     * layer of their own, so they simply travel with whatever the bodies decide. Asked before a node is
     * retired ({@see OperationDraft::supersedeStatusRange()}); patching one value is what {@see set()}
     * is for.
     *
     * @internal Core-only; the retraction paths ask this, extensions patch fields.
     */
    public function isSupersededBy(Contribution $by): bool
    {
        if (! $this->guard->outranksAll($by)) {
            return false;
        }

        foreach ($this->content as $draft) {
            if (! $draft->isSupersededBy($by)) {
                return false;
            }
        }

        return true;
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
     * What one media type publishes of the four example bags, as OpenAPI spells it — `example` or
     * `examples`, never the two together.
     *
     * A declaration outranks an illustration wherever they meet, and a named declaration outranks a
     * singular one: an author who named their examples meant a map. Where the author's map is what
     * publishes, named illustrations JOIN it — a name a producer was handed at a call site is a name
     * somebody chose — and lose every key the author also spelled. A singular declaration publishes
     * alone: there is no map for an illustration to join, and making one would file the author's own
     * example under a key nobody picked.
     *
     * @return array<string, mixed>
     */
    private function illustration(string $mediaType): array
    {
        $declared = $this->declaredExamples[$mediaType] ?? [];
        $illustrated = $this->illustratedExamples[$mediaType] ?? [];

        if ($declared !== []) {
            $merged = $declared + $illustrated;
            ksort($merged);

            return ['examples' => $merged];
        }

        if (array_key_exists($mediaType, $this->declaredExample)) {
            return ['example' => $this->declaredExample[$mediaType]];
        }

        if ($illustrated !== []) {
            return ['examples' => $illustrated];
        }

        return array_key_exists($mediaType, $this->examples) ? ['example' => $this->examples[$mediaType]] : [];
    }

    /**
     * @internal Not part of the frozen extension-author surface — it hands back the (also
     * `@internal`) {@see ResponseObject} document model. Extensions hand drafts back to the pipeline,
     * which freezes them.
     */
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

        $component = Hydrate::stringOrNull($resolved[self::COMPONENT] ?? null);

        unset($resolved['$ref'], $resolved['description'], $resolved['headers'], $resolved[self::COMPONENT]);

        $content = null;
        if ($this->content !== []) {
            $content = [];
            foreach ($this->content as $mediaType => $schema) {
                $content[$mediaType] = ['schema' => $schema->freeze()->toArray()] + $this->illustration($mediaType);
            }
        }

        $docuccino = new NodeExtension(
            id: $this->id,
            provenance: $this->guard->provenance(),
            rest: $component === null ? [] : ['facts' => [self::COMPONENT => $component]
                + ($this->componentNamesResponse ? [self::COMPONENT_NAMES_RESPONSE => true] : [])],
        );

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
