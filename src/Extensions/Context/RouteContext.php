<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Extensions\Contracts\TypeSchemaConverter;
use Docuccino\Core\Extensions\Contracts\ValidationRulesToSchema;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Provenance\Source;
use Docuccino\Core\Provenance\SourcePathResolver;

/**
 * Everything an {@see OperationExtension} needs about the route it documents (design §5): the
 * route, its engine action ref, the collected attributes (method beats class), docblock prose and
 * the document being built. {@see analysis()} hits the engine at most once and memoises, so
 * extensions in different phases share one pass. {@see converter()} works over the document-wide
 * {@see ComponentRegistry} so cross-route `$ref`s stay consistent; each route's fragment gets the
 * delta it added.
 *
 * The gated resolver chains below (response target/status, payload media type, route-binding
 * schema) all take the first non-null answer, so a disabled integration contributes nothing and
 * the default stands.
 */
final class RouteContext
{
    private ?ActionAnalysis $analysis = null;

    private ?TypeSchemaConverter $converter = null;

    private ?RepresentationPolicy $representation = null;

    private ?ValidationRulesToSchema $validation = null;

    /** Files the action analysis didn't surface (traces, integrations); merged into {@see dependencyFiles()}. */
    private RouteDependencies $dependencies;

    /** Facts this route found that the whole document reports; travel on the fragment. {@see notes()}. */
    private RouteNotes $notes;

    /**
     * @param  list<string>  $pathParameters  route template parameter names, in template order
     * @param  list<string>  $optionalPathParameters  the subset declared optional (`{param?}`)
     * @param  array<string, string>  $routeBindings  path parameter name → bound model FQCN
     * @param  array<string, string>  $routeBindingFields  path parameter name → the column it binds on,
     *                                                     for the subset that names one (`{post:slug}`)
     * @param  ?string  $formRequestClass  the FormRequest class type-hinted on the action, if any
     * @param  ?string  $operationId  this operation's stable `x-docuccino.id`, already minted. The
     *                                pipeline stamps it onto the frozen node afterwards, but an
     *                                extension keyed on IDENTITY — a recorded example, filed under the
     *                                id so it survives a route rename — needs it while the draft is
     *                                still open, and deriving it a second time is how two answers to
     *                                "which operation is this" start disagreeing
     *
     * @internal an extension is HANDED a context and never builds one, so the constructor is not part
     * of the author surface — which is what lets it take the whole internal {@see ResolvedExtensions}
     * the chain methods read off. The promoted public properties are still swept by the boundary test.
     */
    public function __construct(
        public readonly RouteDescriptor $route,
        public readonly ActionRef $actionRef,
        public readonly AttributeSet $attributes,
        public readonly TypeEngine $engine,
        public readonly DocumentConfig $document,
        private readonly ResolvedExtensions $extensions = new ResolvedExtensions,
        public readonly array $pathParameters = [],
        public readonly array $optionalPathParameters = [],
        public readonly array $routeBindings = [],
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ComponentRegistry $components = new ComponentRegistry,
        public readonly ?SourcePathResolver $pathResolver = null,
        public readonly ?string $documentedMethod = null,
        public readonly bool $allowsTrashedBindings = false,
        public readonly ?string $formRequestClass = null,
        public readonly array $routeBindingFields = [],
        public readonly ?string $operationId = null,
        public readonly bool $deprecated = false,
    ) {
        $this->dependencies = new RouteDependencies;
        $this->notes = new RouteNotes;
    }

    /** Where to analyse the success body, or null to analyse the dispatched action itself. */
    public function responseAnalysisRedirect(): ?ResponseAnalysisRedirect
    {
        foreach ($this->extensions->responseAnalysisTargets as $target) {
            $redirect = $target->resolve($this);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        return null;
    }

    /**
     * The first exception mapper that both supports the throw and yields a draft, paired with that
     * draft — the one home for that chain resolution. Every extension that synthesizes a throw comes
     * through here and then applies the draft under its own producer and source.
     */
    public function mapThrow(ThrownException $throw): ?MappedResponse
    {
        foreach ($this->extensions->exceptionToResponse as $mapper) {
            if (! $mapper->supports($throw, $this)) {
                continue;
            }

            $draft = $mapper->toResponse($throw, $this, $this->components);
            if ($draft !== null) {
                return new MappedResponse($mapper, $draft);
            }
        }

        return null;
    }

    /**
     * Success-status overrides for a returned class, or `[]` to keep the inferred default.
     *
     * @return list<int>
     */
    public function resolveResponseStatuses(string $fqcn): array
    {
        foreach ($this->extensions->responseStatusResolvers as $resolver) {
            $statuses = $resolver->resolveStatuses($this, $fqcn);
            if ($statuses !== []) {
                return $statuses;
            }
        }

        return [];
    }

    /** The media type a response payload serialises as, defaulting to `application/json`. */
    public function payloadMediaType(DType $payload): string
    {
        foreach ($this->extensions->payloadMediaTypeResolvers as $resolver) {
            $mediaType = $resolver->mediaTypeFor($payload);
            if ($mediaType !== null) {
                return $mediaType;
            }
        }

        return 'application/json';
    }

    /**
     * JSON-schema keywords for a route-bound model's key, or null to fall back to a plain string.
     *
     * @return array<string, mixed>|null
     */
    public function routeBindingKeySchema(string $modelFqcn): ?array
    {
        foreach ($this->extensions->routeBindingSchemaResolvers as $resolver) {
            $schema = $resolver->keySchemaFor($modelFqcn);
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * JSON-schema keywords for the named column a path parameter binds on (`{post:slug}`), or null when
     * nothing in the chain can type it — a separate chain from the route-key one, and deliberately so
     * ({@see RouteBindingFieldSchemaResolver}).
     *
     * @return array<string, mixed>|null
     */
    public function routeBindingFieldSchema(string $modelFqcn, string $field): ?array
    {
        foreach ($this->extensions->routeBindingFieldSchemaResolvers as $resolver) {
            $schema = $resolver->fieldSchemaFor($this, $modelFqcn, $field);
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * The HTTP method this context documents (lower-case). A multi-method route gets one context per
     * documentable method, so anything branching on the verb — request body vs query parameters —
     * must read this and not {@see RouteDescriptor::primaryMethod()}.
     */
    public function httpMethod(): string
    {
        return $this->documentedMethod ?? $this->route->primaryMethod();
    }

    /**
     * The dependency-contribution bag (design §10) — and the home of fragment-cache soundness:
     * anything an extension reads that affects output must be registered here (or in the descriptor
     * cache inputs), because the fragment cache key is exactly these files plus the action
     * analysis's own. A fact read from an unregistered file means editing that file leaves a stale
     * fragment warm.
     */
    public function dependencies(): RouteDependencies
    {
        return $this->dependencies;
    }

    /**
     * The note bag (design §10) — where a fact belongs when the document reports it once for the many
     * routes that found it. Write it here rather than into a document-level aggregate of your own: notes
     * ride the operation fragment, so a warm cache hit replays them, and an aggregate written directly is
     * an aggregate a warm build comes back without. {@see RouteNotes}.
     */
    public function notes(): RouteNotes
    {
        return $this->notes;
    }

    /** The action's inference result, computed once and memoised. */
    public function analysis(): ActionAnalysis
    {
        return $this->analysis ??= $this->engine->analyzeAction($this->actionRef);
    }

    /**
     * Drive an interprocedural {@see TraceVisitor} walk from the action, recording the walk's transitive
     * dependency files. Trace through here rather than calling the engine directly, or those
     * dependencies go unrecorded — see {@see dependencies()}. For any other root, {@see traceFrom()}.
     */
    public function trace(TraceVisitor $visitor): TraceReport
    {
        return $this->traceFrom($this->actionRef, $visitor);
    }

    /**
     * The same walk from a root the action body doesn't reach — the constructor of an injected query
     * object, a closure passed to a facade. Files are recorded as for {@see trace()}.
     */
    public function traceFrom(ActionRef $root, TraceVisitor $visitor): TraceReport
    {
        $report = $this->engine->trace($root, $visitor);

        $this->dependencies->addFiles($report->dependencyFiles);

        return $report;
    }

    /**
     * Record files read out-of-band, e.g. a separately analysed FormRequest. See {@see dependencies()}.
     *
     * @param  list<string>  $files
     */
    public function recordDependencyFiles(array $files): void
    {
        $this->dependencies->addFiles($files);
    }

    /**
     * Every file this route's analysis and traces read, deduped and sorted — the fragment cache key
     * input the pipeline persists.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        $files = array_values(array_unique([...$this->analysis()->dependencyFiles, ...$this->dependencies->files()]));
        sort($files);

        return $files;
    }

    /** The rule→schema chain driver over the resolved transformers; feed it a {@see RuleSet}. */
    public function validation(): ValidationRulesToSchema
    {
        return $this->validation ??= new DefaultValidationRulesToSchema($this->extensions->ruleTransformers);
    }

    /**
     * A provenance {@see Source} for an engine {@see SourceLocation}, path made project-relative.
     * Null when there's no resolver or no usable file — better no source than a churny absolute path.
     */
    public function sourceAt(SourceLocation $location, ?string $symbol = null): ?Source
    {
        if ($this->pathResolver === null || $location->file === '') {
            return null;
        }

        return new Source($this->pathResolver->relative($location->file), $location->line, $symbol);
    }

    /**
     * A provenance {@see Source} pointing at the action itself — the reflection target for
     * attribute-produced contributions, and a fallback for reflection-derived inference.
     */
    public function actionSource(): ?Source
    {
        if ($this->pathResolver === null || $this->actionRef->file === '') {
            return null;
        }

        $line = $this->actionRef->line > 0 ? $this->actionRef->line : null;

        return new Source($this->pathResolver->relative($this->actionRef->file), $line, $this->actionRef->symbol());
    }

    /**
     * The type→schema converter for this route, hoisting into the document-wide component
     * registry so cross-route `$ref`s stay consistent and named schemas dedupe once. The contract is
     * what extensions may rely on; {@see SchemaConverter} behind it is internal.
     */
    public function converter(): TypeSchemaConverter
    {
        // The converter gets this route's dependency bag so mappers recording files via
        // SchemaContext::dependsOn() widen the fragment cache key — see dependencies().
        return $this->converter ??= new SchemaConverter($this->extensions->typeToSchema, $this->engine, $this->components, $this->representation(), $this->dependencies);
    }

    /** The document's representation policy, resolved once. */
    public function representation(): RepresentationPolicy
    {
        return $this->representation ??= RepresentationPolicy::fromConfig(
            $this->document->representation,
            $this->document->integration('api_resources')['wrap'] ?? null,
        );
    }
}
