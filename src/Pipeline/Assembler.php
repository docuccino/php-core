<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Content\CompiledContent;
use Docuccino\Core\Content\ContentResolver;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Identity\ContentHasher;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Overlay\OverlayApplier;
use Docuccino\Core\Overlay\OverlayDocument;

/**
 * Merges operation fragments into a UIR document array: places operations under their path/method,
 * hoists and identifies components, stamps document identity + generator metadata, applies overlays
 * and document transformers, then computes the content hash. Two operations contesting one slot —
 * duplicate identities, or a path and method a fragment already holds — are error diagnostics, and the
 * first claimant keeps the slot; nothing is ever silently overwritten.
 *
 * @internal
 */
final class Assembler
{
    private const SCHEMA_URL = 'https://spec.docuccino.app/uir/1.0/schema.json';

    private const UIR_VERSION = '1.0.0';

    private const OPENAPI_VERSION = '3.2.0';

    private const DIALECT = 'https://spec.openapis.org/oas/3.2/dialect/base';

    public function __construct(
        private readonly string $generatorName,
        private readonly IdentityGenerator $identity = new IdentityGenerator,
        private readonly ContentHasher $contentHasher = new ContentHasher,
        private readonly OverlayApplier $overlays = new OverlayApplier,
        private readonly ContentResolver $content = new ContentResolver,
    ) {}

    /**
     * @param  list<OperationFragment>  $fragments
     * @param  list<OverlayDocument>  $overlayDocuments
     * @param  list<DocumentTransformer>  $transformers
     */
    public function assemble(
        array $fragments,
        DocumentConfig $document,
        string $documentId,
        ComponentRegistry $components,
        array $overlayDocuments,
        array $transformers,
        string $generatorVersion,
        CompiledContent $content = new CompiledContent,
    ): AssemblyResult {
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        $paths = $this->buildPaths($fragments, $diagnostics);
        $componentSchemas = $this->buildComponents($components);

        $doc = [
            '$schema' => self::SCHEMA_URL,
            'uir' => self::UIR_VERSION,
            'openapi' => self::OPENAPI_VERSION,
            'jsonSchemaDialect' => self::DIALECT,
            'info' => $document->info,
        ];

        if ($document->servers !== []) {
            $doc['servers'] = $document->servers;
        }

        $documentSecurity = $document->documentSecurity();
        if ($documentSecurity !== null) {
            $doc['security'] = $documentSecurity;
        }

        $tags = $document->tagDefinitions();
        if ($tags !== []) {
            $doc['tags'] = $tags;
        }

        $doc['paths'] = $paths;

        $componentsOut = [];
        if ($componentSchemas !== []) {
            $componentsOut['schemas'] = $componentSchemas;
        }

        // Registry order is deterministic (routes process sorted) and the canonicalizer sorts keys.
        $responseRenames = $components->responseRenames();
        $componentResponses = ComponentNames::rekey($components->responses(), $responseRenames);
        if ($componentResponses !== []) {
            $componentsOut['responses'] = $componentResponses;
        }

        // Explicit config schemes win over integration-contributed ones (Sanctum/Passport). The
        // renames are applied before the merge, so a config scheme of the same name is never rekeyed
        // by a map that was derived from the registry alone.
        $schemeRenames = $components->securitySchemeRenames();
        $securitySchemes = $document->securitySchemes() + ComponentNames::rekey($components->securitySchemes(), $schemeRenames);
        if ($securitySchemes !== []) {
            $componentsOut['securitySchemes'] = $securitySchemes;
        }

        if ($componentsOut !== []) {
            $doc['components'] = $componentsOut;
        }

        // Registration named components first-come; this is where the ones two claimants contested take
        // the names they are published under, references included.
        $doc = $this->publishSchemaNames($doc, $components->schemaRenames());
        $doc = ComponentNames::rename($doc, $responseRenames, 'responses');
        $doc = $this->publishSecuritySchemeNames($doc, $schemeRenames);

        $doc['x-docuccino'] = [
            'document' => ['id' => $documentId, 'configHash' => $document->hash()],
            'generator' => ['name' => $this->generatorName, 'version' => $generatorVersion, 'specVersion' => self::UIR_VERSION],
        ];

        foreach ($components->diagnostics() as $diagnostic) {
            $diagnostics[] = $diagnostic;
        }

        // The three document-wide name spaces, all read off the finished build rather than reported as
        // each route passes: that is what keeps them intact on a warm cache hit, where no route runs.
        foreach ($components->nameCollisions() as $diagnostic) {
            $diagnostics[] = $diagnostic;
        }
        $this->reportDuplicateOperationIds($fragments, $diagnostics);
        $this->reportTagCollisions($fragments, $document, $diagnostics);

        $doc = $this->applyOverlays($doc, $overlayDocuments, $diagnostics);
        $doc = $this->applyTransformers($doc, $document, $documentId, $transformers, $diagnostics);

        // Content resolves against the now-final document, so directives and nav refs see overlay
        // and transformer changes — and lands before the hash, so a prose edit or nav move shows up
        // as a (non-breaking) change.
        $doc = $this->applyContent($doc, $content, $diagnostics);

        $doc = $this->stampContentHash($doc);

        return new AssemblyResult($doc, $diagnostics);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function applyContent(array $doc, CompiledContent $content, array &$diagnostics): array
    {
        [$resolved, $contentDiagnostics] = $this->content->resolve($content, $doc);
        foreach ($contentDiagnostics as $diagnostic) {
            $diagnostics[] = $diagnostic;
        }

        // An empty content tree leaves no empty `content` key behind.
        if ($resolved->isEmpty()) {
            return $doc;
        }

        $extension = is_array($doc['x-docuccino'] ?? null) ? $doc['x-docuccino'] : [];
        $extension['content'] = $resolved->toArray();
        $doc['x-docuccino'] = $extension;

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function stampContentHash(array $doc): array
    {
        $extension = is_array($doc['x-docuccino'] ?? null) ? $doc['x-docuccino'] : [];
        $meta = is_array($extension['document'] ?? null) ? $extension['document'] : [];

        $meta['contentHash'] = $this->contentHasher->hash($doc);
        $extension['document'] = $meta;
        $doc['x-docuccino'] = $extension;

        return $doc;
    }

    /**
     * @param  list<OperationFragment>  $fragments
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, array<string, mixed>>
     */
    private function buildPaths(array $fragments, array &$diagnostics): array
    {
        $paths = [];
        $seenIds = [];
        /** @var array<string, array<string, string>> $claimed */
        $claimed = [];

        foreach ($fragments as $fragment) {
            $operationId = $fragment->operation->docuccino?->id;
            $sharedWith = $operationId === null ? null : ($seenIds[$operationId] ?? null);

            // Only when the two are genuinely different slots. An identity is a function of the method,
            // the path SHAPE and the host, so a repeat on one path and method is the slot collision
            // below said a second time — what reaches here is two paths whose parameters are merely
            // named differently (`{user}` and `{id}` normalise alike), which loses no operation but
            // leaves a semantic diff unable to tell the pair apart.
            if ($sharedWith !== null && $sharedWith !== $fragment->path) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Error,
                    code: 'identity.duplicate-operation',
                    message: sprintf(
                        'Two routes resolve to the same operation identity (%s): %s and %s. A path parameter\'s name is not part of an identity, so a semantic diff pairs the two as one operation.',
                        $operationId,
                        $sharedWith,
                        $fragment->path,
                    ),
                    routeSignature: $fragment->routeSignature,
                    help: 'Give one of them a path that differs by more than a parameter name.',
                );
            }
            if ($operationId !== null) {
                $seenIds[$operationId] ??= $fragment->path;
            }

            $holder = $claimed[$fragment->path][$fragment->method] ?? null;
            if ($holder !== null) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Error,
                    code: 'paths.operation-collision',
                    message: sprintf(
                        'OpenAPI documents one operation per path and method, and %s %s is already held by %s; this route is not in the document.',
                        strtoupper($fragment->method),
                        $fragment->path,
                        $holder,
                    ),
                    routeSignature: $fragment->routeSignature,
                    // Two routes with the SAME signature are one route registered twice, and telling
                    // that author about hosts is advice about something they do not have.
                    help: $holder === $fragment->routeSignature
                        ? 'The same route is registered twice — remove one of the registrations.'
                        : 'Routes that differ only by host are separate APIs to a reader: give each host its own document and filter the routes into it.',
                );

                continue;
            }

            $claimed[$fragment->path][$fragment->method] = $fragment->routeSignature;
            $paths[$fragment->path][$fragment->method] = $fragment->operation->toArray();
        }

        return $paths;
    }

    /**
     * Rewrite the document onto the component names the registry publishes: rekey
     * `components.schemas` and repoint every `$ref` that named one of them.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, string>  $renames
     * @return array<string, mixed>
     */
    private function publishSchemaNames(array $doc, array $renames): array
    {
        if ($renames === []) {
            return $doc;
        }

        /** @var array<string, mixed> $doc */
        $doc = ComponentNames::rename($doc, $renames);

        $components = $doc['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;
        if (! is_array($components) || ! is_array($schemas)) {
            return $doc;
        }

        /** @var array<string, mixed> $schemas */
        $components['schemas'] = ComponentNames::rekey($schemas, $renames);
        $doc['components'] = $components;

        return $doc;
    }

    /**
     * Repoint the `security` requirements a security-scheme rename moved. A requirement names its
     * scheme as a KEY rather than through a `$ref`, so no reference walk reaches it — this is the one
     * shape that has to be rewritten by hand.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, string>  $renames
     * @return array<string, mixed>
     */
    private function publishSecuritySchemeNames(array $doc, array $renames): array
    {
        if ($renames === []) {
            return $doc;
        }

        if (is_array($doc['security'] ?? null)) {
            $doc['security'] = self::rekeyRequirements($doc['security'], $renames);
        }

        $paths = $doc['paths'] ?? null;
        if (! is_array($paths)) {
            return $doc;
        }

        foreach ($paths as $path => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! is_array($operation) || ! is_array($operation['security'] ?? null)) {
                    continue;
                }

                $operation['security'] = self::rekeyRequirements($operation['security'], $renames);
                $operations[$method] = $operation;
            }

            $paths[$path] = $operations;
        }

        $doc['paths'] = $paths;

        return $doc;
    }

    /**
     * @param  array<array-key, mixed>  $security
     * @param  array<string, string>  $renames
     * @return list<array<string, mixed>>
     */
    private static function rekeyRequirements(array $security, array $renames): array
    {
        $out = [];
        foreach ($security as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $renamed = [];
            foreach ($requirement as $name => $scopes) {
                $renamed[$renames[(string) $name] ?? (string) $name] = $scopes;
            }

            $out[] = $renamed;
        }

        return $out;
    }

    /**
     * One warning per pair of routes that ended up sharing an `operationId`. Unlike the operation
     * IDENTITY above, nothing in the document is lost — but an operationId is what a client generator
     * names its function after, so two of them is a broken SDK rather than a cosmetic clash.
     *
     * @param  list<OperationFragment>  $fragments
     * @param  list<Diagnostic>  $diagnostics
     */
    private function reportDuplicateOperationIds(array $fragments, array &$diagnostics): void
    {
        $seen = [];

        foreach ($fragments as $fragment) {
            $operationId = $fragment->operation->operationId;
            if ($operationId === null || $operationId === '') {
                continue;
            }

            if (isset($seen[$operationId])) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'identity.duplicate-operation-id',
                    message: sprintf('operationId "%s" is claimed by both %s and %s; a generated client names one function for the pair.', $operationId, $seen[$operationId], $fragment->routeSignature),
                    routeSignature: $fragment->routeSignature,
                    help: 'Give one of them its own id with #[OperationId], or name the routes distinctly.',
                );

                continue;
            }

            $seen[$operationId] = $fragment->routeSignature;
        }
    }

    /**
     * One info per tag that two different controllers landed under by default. Merging tags is not
     * itself wrong — a tag is a display grouping, nothing is renamed and no shape is lost, and an
     * author may well have meant `Api\UserController` and `Admin\UserController` to read as one
     * `User` group — so this reports the merge and names the two ways out rather than splitting a
     * grouping nobody asked to split.
     *
     * @param  list<OperationFragment>  $fragments
     * @param  list<Diagnostic>  $diagnostics
     */
    private function reportTagCollisions(array $fragments, DocumentConfig $document, array &$diagnostics): void
    {
        /** @var array<string, array<string, true>> $claimants */
        $claimants = [];

        foreach ($fragments as $fragment) {
            $class = $fragment->actionClass;
            $tag = $class === null ? null : $document->defaultTag($class);

            // Only the tag the default strategy derived: an explicit #[Group] on both controllers is
            // the author saying they belong together, and reporting that would be noise.
            if ($tag !== null && in_array($tag, $fragment->operation->tags, true)) {
                $claimants[$tag][$class] = true;
            }
        }

        ksort($claimants);

        foreach ($claimants as $tag => $classes) {
            if (count($classes) < 2) {
                continue;
            }

            $names = array_keys($classes);
            sort($names);

            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'tags.name-collision',
                message: sprintf('Tag "%s" is the default tag of distinct controllers (%s), so their operations are grouped together.', $tag, implode(', ', $names)),
                help: 'Intended? Nothing to do. Otherwise separate them with #[Group], or rename one through the tags.map config option.',
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildComponents(ComponentRegistry $components): array
    {
        $schemas = $components->schemas();
        if ($schemas === []) {
            return [];
        }

        $schemaIds = $components->schemaIds();

        $out = [];
        foreach ($schemas as $name => $schema) {
            $id = isset($schemaIds[$name])
                ? $this->identity->namedSchemaId($schemaIds[$name])
                : $this->identity->inlineSchemaId($schema);

            $out[$name] = ['x-docuccino' => ['id' => $id]] + $schema;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<OverlayDocument>  $overlayDocuments
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function applyOverlays(array $doc, array $overlayDocuments, array &$diagnostics): array
    {
        foreach ($overlayDocuments as $overlay) {
            $result = $this->overlays->apply($doc, $overlay);
            $doc = $result->document;
            foreach ($result->diagnostics as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }
        }

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<DocumentTransformer>  $transformers
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function applyTransformers(array $doc, DocumentConfig $document, string $documentId, array $transformers, array &$diagnostics): array
    {
        if ($transformers === []) {
            return $doc;
        }

        $draft = new UirDocumentDraft($doc);
        $context = new DocumentContext($document, $documentId);
        foreach ($transformers as $transformer) {
            $transformer->transform($draft, $context);
        }

        foreach ($context->diagnostics->all() as $diagnostic) {
            $diagnostics[] = $diagnostic;
        }

        return $draft->toArray();
    }
}
