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
 * Downlevels pure OpenAPI 3.2 to 3.1 for toolchains that lag the 3.2 release. Lossy transforms warn
 * into an {@see EmitReport} rather than silently dropping things:
 *
 * - `openapi` becomes `3.1.1`;
 * - the 3.2 `jsonSchemaDialect` base is rewritten to the 3.1 base;
 * - the 3.2-only `query` HTTP method is dropped (warning);
 * - the 3.2-only `additionalOperations` path-item member is dropped (warning);
 * - the 3.2-only tag members `summary`, `parent` and `kind` are dropped (a warning each).
 *
 * The rest of 3.2 is compatible with 3.1's JSON Schema dialect and passes through unchanged.
 *
 * @internal
 */
final readonly class OpenApi31DownlevelEmitter implements ReportingEmitter
{
    private const string DIALECT_32 = 'https://spec.openapis.org/oas/3.2/dialect/base';

    private const string DIALECT_31 = 'https://spec.openapis.org/oas/3.1/dialect/base';

    /** The 3.2-only Tag Object members, each with the code and the loss its warning reports. */
    private const array TAG_MEMBERS_32 = [
        'summary' => ['downlevel.tag-summary', 'Tags fall back to their `name` for display.'],
        'parent' => ['downlevel.tag-parent', 'The tag hierarchy flattens; nest the naming instead (e.g. "Billing / Invoices").'],
        'kind' => ['downlevel.tag-kind', 'Tag categorisation is lost; 3.1 consumers treat every tag the same.'],
    ];

    /**
     * What a dropped `parent` costs when the document ALSO states the forest as `x-tagGroups`, which
     * 3.1 keeps. Read off the document rather than assumed: an artifact written before anything
     * projected the groups carries `parent` alone, and telling its author nothing was lost would be
     * false.
     */
    private const string PARENT_HELP_WITH_GROUPS = 'Only the 3.2 `parent` member is dropped; this document also states the hierarchy as `x-tagGroups`, which the renderers reading that convention still group by.';

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
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        $canonical = $this->canonicalizer->canonicalize($this->toOpenApiArray($document, $diagnostics, $options));

        $output = $options->yaml
            ? $this->yaml->serialize($canonical)
            : $this->serializer->serialize($canonical);

        return new EmitResult($output, new EmitReport($diagnostics));
    }

    /**
     * The pure OpenAPI 3.1 array (pre-canonicalisation), reused by the 3.0 downlevel emitter — 3.0
     * needs the 3.2-only constructs gone before its own restrictions apply.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    public function toOpenApiArray(UirDocument $document, array &$diagnostics, EmitOptions $options = new EmitOptions): array
    {
        // The 3.2 emitter's own array is pre-`ServerVariables` — the Postman emitter reads it too, and a
        // collection answers a defaultless variable its own way — so the OpenAPI answer is applied here,
        // where 3.0 inherits it by chaining off this method.
        $oas32 = ServerVariables::complete($this->oas32->toOpenApiArray($document, $options), $diagnostics);

        return $this->downlevel($oas32, $diagnostics);
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

        if (isset($array['tags']) && is_array($array['tags'])) {
            $array['tags'] = $this->downlevelTags($array['tags'], $diagnostics, isset($array['x-tagGroups']));
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
     * @param  array<mixed, mixed>  $tags
     * @param  list<Diagnostic>  $diagnostics
     * @return list<mixed>
     */
    private function downlevelTags(array $tags, array &$diagnostics, bool $hasTagGroups): array
    {
        $out = [];

        foreach ($tags as $tag) {
            if (! is_array($tag)) {
                $out[] = $tag;

                continue;
            }

            $name = is_string($tag['name'] ?? null) ? $tag['name'] : '(unnamed)';

            foreach (self::TAG_MEMBERS_32 as $member => [$code, $help]) {
                if (! isset($tag[$member])) {
                    continue;
                }

                unset($tag[$member]);
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: $code,
                    message: sprintf(
                        'Dropped the OpenAPI 3.2 `%s` member from tag `%s`, which OpenAPI 3.1 does not define.',
                        $member,
                        $name,
                    ),
                    help: $member === 'parent' && $hasTagGroups ? self::PARENT_HELP_WITH_GROUPS : $help,
                );
            }

            $out[] = Arr::stringKeyed($tag);
        }

        return $out;
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
                message: 'Dropped the OpenAPI 3.2 `query` HTTP method, which OpenAPI 3.1 and below do not define.',
                routeSignature: 'QUERY '.$template,
                help: 'Consumers on 3.1 toolchains will not see this operation; keep the 3.2 artifact for full fidelity.',
            );
        }

        if (isset($item['additionalOperations'])) {
            unset($item['additionalOperations']);
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.additional-operations',
                message: 'Dropped the OpenAPI 3.2 `additionalOperations` member, which OpenAPI 3.1 and below do not define.',
                routeSignature: $template,
                help: 'Model custom HTTP methods with a standard method on 3.1 toolchains.',
            );
        }

        return Arr::stringKeyed($item);
    }
}
