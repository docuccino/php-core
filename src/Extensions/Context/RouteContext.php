<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Contracts\ValidationRulesToSchema;
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
 * Everything an {@see OperationExtension} needs about the route it is documenting (design §5):
 * the discovered route, its engine action reference, the collected attributes (method > class),
 * docblock prose, and the document being built. The inference handle is lazy — {@see analysis()}
 * calls the engine at most once per context and memoises, so extensions in different phases
 * share one analysis pass. {@see converter()} exposes the resolved type→schema chain over the
 * document-wide {@see ComponentRegistry} (shared across routes so cross-route `$ref`s stay
 * consistent); the components a route newly registered are collected into its fragment — as a
 * delta of that shared registry — after the pipeline runs.
 */
final class RouteContext
{
    private ?ActionAnalysis $analysis = null;

    private ?SchemaConverter $converter = null;

    private ?RepresentationPolicy $representation = null;

    private ?ValidationRulesToSchema $validation = null;

    /**
     * The out-of-band dependency-file contributions for this route (traces + integrations reading
     * files the action analysis did not surface). Merged into {@see dependencyFiles()}.
     */
    private RouteDependencies $dependencies;

    /**
     * @param  list<TypeToSchema>  $typeMappers  the resolved type→schema chain (document-wide)
     * @param  list<ExceptionToResponse>  $exceptionMappers  the resolved exception→response chain
     * @param  list<RuleTransformer>  $ruleTransformers  the resolved validation rule vocabulary chain
     * @param  list<string>  $pathParameters  route template parameter names, in template order
     * @param  list<string>  $optionalPathParameters  the subset declared optional (`{param?}`)
     * @param  array<string, string>  $routeBindings  path parameter name → bound model FQCN
     * @param  list<ResponseAnalysisTarget>  $responseAnalysisTargets  gated success-body analysis redirects
     * @param  list<ResponseStatusResolver>  $responseStatusResolvers  gated success-status overrides
     * @param  list<PayloadMediaTypeResolver>  $payloadMediaTypeResolvers  gated response media-type matchers
     * @param  list<RouteBindingSchemaResolver>  $routeBindingSchemaResolvers  gated route-key schema typers
     * @param  ?string  $formRequestClass  the FormRequest class type-hinted on the action, if any
     */
    public function __construct(
        public readonly RouteDescriptor $route,
        public readonly ActionRef $actionRef,
        public readonly AttributeSet $attributes,
        public readonly TypeEngine $engine,
        public readonly DocumentConfig $document,
        public readonly array $typeMappers = [],
        public readonly array $exceptionMappers = [],
        public readonly array $ruleTransformers = [],
        public readonly array $pathParameters = [],
        public readonly array $optionalPathParameters = [],
        public readonly array $routeBindings = [],
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ComponentRegistry $components = new ComponentRegistry,
        public readonly ?SourcePathResolver $pathResolver = null,
        public readonly ?string $documentedMethod = null,
        public readonly bool $allowsTrashedBindings = false,
        public readonly array $responseAnalysisTargets = [],
        public readonly array $responseStatusResolvers = [],
        public readonly array $payloadMediaTypeResolvers = [],
        public readonly array $routeBindingSchemaResolvers = [],
        public readonly ?string $formRequestClass = null,
    ) {
        $this->dependencies = new RouteDependencies;
    }

    /**
     * The success-body analysis redirect for this route from the gated {@see ResponseAnalysisTarget}
     * chain (first non-null wins), or null to analyse the dispatched action itself. A disabled
     * integration contributes no target, so a plain inference is used and attributed honestly.
     */
    public function responseAnalysisRedirect(): ?ResponseAnalysisRedirect
    {
        foreach ($this->responseAnalysisTargets as $target) {
            $redirect = $target->resolve($this);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        return null;
    }

    /**
     * Walk the exception→response chain for a throw and return the first mapper that supports it AND
     * yields a non-null {@see ResponseDraft}, paired with that draft (or null when none applies). The
     * single home for the "first supports() + non-null toResponse() wins" chain resolution the error /
     * implicit / QB-strict / action-authorize extensions all synthesize a throw for — each then applies
     * the returned draft with its own producer/source.
     */
    public function mapThrow(ThrownException $throw): ?MappedResponse
    {
        foreach ($this->exceptionMappers as $mapper) {
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
     * The success-status override(s) for a returned class from the gated {@see ResponseStatusResolver}
     * chain (first resolver returning a non-empty list wins), or `[]` to keep the inferred default. A
     * disabled integration contributes nothing.
     *
     * @return list<int>
     */
    public function resolveResponseStatuses(string $fqcn): array
    {
        foreach ($this->responseStatusResolvers as $resolver) {
            $statuses = $resolver->resolveStatuses($this, $fqcn);
            if ($statuses !== []) {
                return $statuses;
            }
        }

        return [];
    }

    /**
     * The media type a response payload serialises as, from the gated {@see PayloadMediaTypeResolver}
     * chain (first non-null wins), defaulting to `application/json`. A disabled resource integration
     * contributes no matcher, so its resources stay `application/json`.
     */
    public function payloadMediaType(DType $payload): string
    {
        foreach ($this->payloadMediaTypeResolvers as $resolver) {
            $mediaType = $resolver->mediaTypeFor($payload);
            if ($mediaType !== null) {
                return $mediaType;
            }
        }

        return 'application/json';
    }

    /**
     * The JSON-schema keywords for a route-bound model's key, from the gated
     * {@see RouteBindingSchemaResolver} chain (first non-null wins), or null to fall back to a plain
     * string. A disabled Eloquent integration contributes nothing.
     *
     * @return array<string, mixed>|null
     */
    public function routeBindingKeySchema(string $modelFqcn): ?array
    {
        foreach ($this->routeBindingSchemaResolvers as $resolver) {
            $schema = $resolver->keySchemaFor($modelFqcn);
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * The specific HTTP method this context documents (lower-case). A multi-method route builds one
     * context per documentable method (arch F8), so extensions that branch on the verb — request
     * body vs query parameters — must read THIS, not {@see RouteDescriptor::primaryMethod()}.
     */
    public function httpMethod(): string
    {
        return $this->documentedMethod ?? $this->route->primaryMethod();
    }

    /**
     * The dependency-contribution bag (design §10): extensions reading facts out-of-band register
     * the FILES those facts came from here, so editing any of them invalidates the cached fragment.
     */
    public function dependencies(): RouteDependencies
    {
        return $this->dependencies;
    }

    /** The action's inference result, computed once and memoised. */
    public function analysis(): ActionAnalysis
    {
        return $this->analysis ??= $this->engine->analyzeAction($this->actionRef);
    }

    /**
     * Drive an interprocedural {@see TraceVisitor} walk from the action, recording the walk's
     * transitive dependency files so they join the fragment cache key (design §10 — a chain traced
     * N files deep must invalidate when any of those files change). Integrations that recover facts
     * by tracing (validation rules, query builders) should go through this rather than the engine
     * directly, so their dependencies are accounted for.
     */
    public function trace(TraceVisitor $visitor): TraceReport
    {
        $report = $this->engine->trace($this->actionRef, $visitor);

        $this->dependencies->addFiles($report->dependencyFiles);

        return $report;
    }

    /**
     * Record extra dependency files an integration read out-of-band (e.g. a FormRequest class it
     * analysed separately) so they join the fragment cache key alongside the action's own.
     *
     * @param  list<string>  $files
     */
    public function recordDependencyFiles(array $files): void
    {
        $this->dependencies->addFiles($files);
    }

    /**
     * Every file this route's analysis + traces read, deduped and sorted — the fragment cache key
     * input the pipeline persists (design §10).
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        $files = array_values(array_unique([...$this->analysis()->dependencyFiles, ...$this->dependencies->files()]));
        sort($files);

        return $files;
    }

    /**
     * The validation rule → schema converter for this route: the core chain driver over the
     * resolved rule-transformer vocabulary (Laravel's set plus any user transformers). Recovery
     * integrations feed it a {@see RuleSet}.
     */
    public function validation(): ValidationRulesToSchema
    {
        return $this->validation ??= new DefaultValidationRulesToSchema($this->ruleTransformers);
    }

    /**
     * A provenance {@see Source} for an engine {@see SourceLocation}, with the file path made
     * project-root-relative via the resolver (design §4). Returns null when no resolver or no
     * usable file is available, so a contribution simply carries no source rather than a churny
     * absolute path.
     */
    public function sourceAt(SourceLocation $location, ?string $symbol = null): ?Source
    {
        if ($this->pathResolver === null || $location->file === '') {
            return null;
        }

        return new Source($this->pathResolver->relative($location->file), $location->line, $symbol);
    }

    /**
     * A provenance {@see Source} pointing at the action itself (the reflection target for
     * attribute-produced contributions, and a fallback location for reflection-derived inference).
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
     * registry so cross-route `$ref`s stay consistent and named schemas dedupe once.
     */
    public function converter(): SchemaConverter
    {
        // Share this route's dependency bag with the converter so mappers recording reflected /
        // classMetadata / enum files via SchemaContext::dependsOn() widen the fragment cache key
        // (design §10 — editing a returned DTO/model/enum must invalidate the warm fragment).
        return $this->converter ??= new SchemaConverter($this->typeMappers, $this->engine, $this->components, $this->representation(), $this->dependencies);
    }

    /** The document's representation policy (design §Representation policies), resolved once. */
    public function representation(): RepresentationPolicy
    {
        return $this->representation ??= RepresentationPolicy::fromConfig(
            $this->document->representation,
            $this->document->integration('api_resources')['wrap'] ?? null,
        );
    }
}
