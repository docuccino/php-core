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
     * `$isStatusDefault` is how a producer says the name is the one it derives from the status rather
     * than one anything named — the difference between "nobody has named this body" and "this body is
     * called that". Only the writer knows it: a later reader comparing the value against the default
     * table cannot tell a deliberate `#[ErrorComponent("NotFound")]` on a 404 from the default it
     * happens to spell.
     */
    public function claimComponentName(?string $name, Contribution $by, bool $isStatusDefault = false): PatchResult
    {
        $result = $this->guard->apply(
            self::COMPONENT,
            $name !== null && ComponentNames::isLegal($name) ? $name : null,
            $by,
        );

        if ($result === PatchResult::Accepted) {
            $this->componentIsStatusDefault = $isStatusDefault;
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
            rest: $component === null ? [] : ['facts' => [self::COMPONENT => $component]],
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
