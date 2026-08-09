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
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Identity\ContentHasher;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Overlay\OverlayApplier;
use Docuccino\Core\Overlay\OverlayDocument;

/**
 * Merges operation fragments into a UIR document array: places operations under their path/method,
 * hoists and identifies components, stamps document identity + generator metadata, applies overlays
 * and document transformers, then computes the content hash. Duplicate operation identities (two
 * routes claiming one `GET /x`) are error diagnostics, never silent overwrites.
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
        $componentResponses = $components->responses();
        if ($componentResponses !== []) {
            $componentsOut['responses'] = $componentResponses;
        }

        // Explicit config schemes win over integration-contributed ones (Sanctum/Passport).
        $securitySchemes = $document->securitySchemes() + $components->securitySchemes();
        if ($securitySchemes !== []) {
            $componentsOut['securitySchemes'] = $securitySchemes;
        }

        if ($componentsOut !== []) {
            $doc['components'] = $componentsOut;
        }

        $doc['x-docuccino'] = [
            'document' => ['id' => $documentId, 'configHash' => $document->hash()],
            'generator' => ['name' => $this->generatorName, 'version' => $generatorVersion, 'specVersion' => self::UIR_VERSION],
        ];

        foreach ($components->diagnostics() as $diagnostic) {
            $diagnostics[] = $diagnostic;
        }

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

        foreach ($fragments as $fragment) {
            $operationId = $fragment->operation->docuccino?->id;

            if ($operationId !== null && isset($seenIds[$operationId])) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Error,
                    code: 'identity.duplicate-operation',
                    message: sprintf('Two routes resolve to the same operation identity (%s); one shadows the other.', $operationId),
                    routeSignature: $fragment->routeSignature,
                );
            }
            if ($operationId !== null) {
                $seenIds[$operationId] = true;
            }

            $paths[$fragment->path][$fragment->method] = $fragment->operation->toArray();
        }

        return $paths;
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
