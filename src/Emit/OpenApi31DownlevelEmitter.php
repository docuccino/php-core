<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\Arr;

/**
 * Downlevels pure OpenAPI 3.2 to 3.1 for toolchains that lag the 3.2 release. Each transform
 * is honest — lossy ones raise a warning collected into an {@see EmitReport}:
 *
 * - `openapi` is set to `3.1.1`;
 * - `jsonSchemaDialect`, when it is the 3.2 dialect base, is rewritten to the 3.1 base;
 * - the 3.2-only `query` HTTP method on any path item is dropped (warning);
 * - the 3.2-only `additionalOperations` path-item member is dropped (warning).
 *
 * Everything else in 3.2 is a superset-compatible subset of 3.1's JSON Schema dialect and
 * passes through unchanged.
 *
 * @internal
 */
final readonly class OpenApi31DownlevelEmitter implements Emitter
{
    private const string DIALECT_32 = 'https://spec.openapis.org/oas/3.2/dialect/base';

    private const string DIALECT_31 = 'https://spec.openapis.org/oas/3.1/dialect/base';

    public function __construct(
        private OpenApi32Emitter $oas32 = new OpenApi32Emitter,
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
        private YamlSerializer $yaml = new YamlSerializer,
    ) {}

    public function format(): string
    {
        return 'openapi-3.1';
    }

    public function emit(UirDocument $document, EmitOptions $options = new EmitOptions): string
    {
        return $this->emitWithReport($document, $options)->output;
    }

    public function emitWithReport(UirDocument $document, EmitOptions $options = new EmitOptions): EmitResult
    {
        $array = $this->oas32->toOpenApiArray($document, $options);

        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        $downlevelled = $this->downlevel($array, $diagnostics);

        $canonical = $this->canonicalizer->canonicalize($downlevelled);

        $output = $options->yaml
            ? $this->yaml->serialize($canonical)
            : $this->serializer->serialize($canonical);

        return new EmitResult($output, new EmitReport($diagnostics));
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevel(array $array, array &$diagnostics): array
    {
        $array['openapi'] = '3.1.1';

        if (($array['jsonSchemaDialect'] ?? null) === self::DIALECT_32) {
            $array['jsonSchemaDialect'] = self::DIALECT_31;
        }

        if (isset($array['paths']) && is_array($array['paths'])) {
            $array['paths'] = $this->downlevelPathMap($array['paths'], $diagnostics);
        }

        if (isset($array['webhooks']) && is_array($array['webhooks'])) {
            $array['webhooks'] = $this->downlevelPathMap($array['webhooks'], $diagnostics);
        }

        if (isset($array['components']) && is_array($array['components'])) {
            $components = Arr::stringKeyed($array['components']);
            $pathItems = $components['pathItems'] ?? null;

            if (is_array($pathItems)) {
                $components['pathItems'] = $this->downlevelPathMap($pathItems, $diagnostics);
                $array['components'] = $components;
            }
        }

        return $array;
    }

    /**
     * @param  array<mixed, mixed>  $paths
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelPathMap(array $paths, array &$diagnostics): array
    {
        $out = [];

        foreach ($paths as $template => $item) {
            $template = (string) $template;

            if (is_array($item)) {
                $out[$template] = $this->downlevelPathItem($template, $item, $diagnostics);

                continue;
            }

            $out[$template] = $item;
        }

        return $out;
    }

    /**
     * @param  array<mixed, mixed>  $item
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelPathItem(string $template, array $item, array &$diagnostics): array
    {
        if (isset($item['query'])) {
            unset($item['query']);
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.query-method',
                message: 'Dropped the OpenAPI 3.2 `query` HTTP method, which OpenAPI 3.1 does not define.',
                routeSignature: 'QUERY '.$template,
                help: 'Consumers on 3.1 toolchains will not see this operation; keep the 3.2 artifact for full fidelity.',
            );
        }

        if (isset($item['additionalOperations'])) {
            unset($item['additionalOperations']);
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.additional-operations',
                message: 'Dropped the OpenAPI 3.2 `additionalOperations` member, which OpenAPI 3.1 does not define.',
                routeSignature: $template,
                help: 'Model custom HTTP methods with a standard method on 3.1 toolchains.',
            );
        }

        return Arr::stringKeyed($item);
    }
}
