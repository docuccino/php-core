<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Json;

/**
 * Accumulates the reusable schema/response components hoisted during a build: structurally-equal
 * registrations dedupe, genuine name collisions get a deterministic numeric suffix plus a warning.
 * The `schemaId` hint (an FQCN) is remembered per component so the assembler can pin its diff
 * identity via {@see IdentityGenerator::namedSchemaId()}.
 */
final class ComponentRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $schemas = [];

    /**
     * @var array<string, string>
     */
    private array $schemaIds = [];

    /**
     * Names reserved for a schema identity before its body exists, so a self-reference found
     * mid-expansion resolves to the same (possibly suffixed) name.
     *
     * @var array<string, string> final name → schemaId
     */
    private array $reservedIds = [];

    /**
     * Reusable response components for `components.responses` — e.g. the shared `Problem*` responses
     * a Problem Details preset references from many operations.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $responses = [];

    /**
     * Security schemes contributed by integrations, e.g. Sanctum `bearer` or Passport `oauth2` when
     * the package is installed and config set no scheme. The assembler merges these under the config
     * schemes, so explicit config wins.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $securitySchemes = [];

    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    /**
     * Register a named schema and return the `{"$ref": …}` array pointing at its final name.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function reference(string $name, array $schema, ?string $schemaId = null): array
    {
        return ['$ref' => '#/components/schemas/'.$this->registerSchema($name, $schema, $schemaId)];
    }

    /**
     * Register a named schema, returning the final component name (suffixed on genuine collision).
     * A structurally-identical re-registration under the same name is deduped.
     *
     * @param  array<string, mixed>  $schema
     */
    public function registerSchema(string $name, array $schema, ?string $schemaId = null): string
    {
        if ($schemaId !== null) {
            // Same identity (same class FQCN) already registered — reuse it, so one class is one
            // component however often it's referenced.
            $existing = array_search($schemaId, $this->schemaIds, true);
            if ($existing !== false) {
                return (string) $existing;
            }

            // Materialise into the name reserved up front for a self-referential class whose
            // cycle-breaking $ref went out during expansion.
            $reserved = array_search($schemaId, $this->reservedIds, true);
            if ($reserved !== false) {
                $reserved = (string) $reserved;
                unset($this->reservedIds[$reserved]);
                $this->schemas[$reserved] = $schema;
                $this->schemaIds[$reserved] = $schemaId;

                return $reserved;
            }
        }

        $name = self::sanitize($name);

        if (! isset($this->schemas[$name]) && ! isset($this->reservedIds[$name])) {
            $this->schemas[$name] = $schema;
            if ($schemaId !== null) {
                $this->schemaIds[$name] = $schemaId;
            }

            return $name;
        }

        if (isset($this->schemas[$name]) && self::structurallyEqual($this->schemas[$name], $schema)) {
            return $name;
        }

        $suffixed = $name;
        $n = 1;
        while (
            (isset($this->schemas[$suffixed]) && ! self::structurallyEqual($this->schemas[$suffixed], $schema))
            || isset($this->reservedIds[$suffixed])
        ) {
            $n++;
            $suffixed = $name.'_'.$n;
        }

        if (! isset($this->schemas[$suffixed])) {
            $this->schemas[$suffixed] = $schema;
            if ($schemaId !== null) {
                $this->schemaIds[$suffixed] = $schemaId;
            }
            $this->diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-collision',
                message: sprintf('Two distinct schemas claimed component name "%s"; the second was hoisted as "%s".', $name, $suffixed),
                help: 'Disambiguate with #[SchemaName] on one of the source classes.',
            );
        }

        return $suffixed;
    }

    /**
     * Reserve the final component name for a schema identity before its body is built, so a
     * self-reference found mid-expansion can `$ref` the exact name — collision suffix included —
     * that the schema will materialise under. The registry is the sole owner of component naming:
     * reserving one identity twice gives the same name, and a reservation occupies the namespace so
     * a different identity gets suffixed past it.
     */
    public function reserveSchemaName(string $name, string $schemaId): string
    {
        // Already materialised or reserved under this identity — reuse that name.
        $existing = array_search($schemaId, $this->schemaIds, true);
        if ($existing !== false) {
            return (string) $existing;
        }
        $reserved = array_search($schemaId, $this->reservedIds, true);
        if ($reserved !== false) {
            return (string) $reserved;
        }

        $name = self::sanitize($name);
        $final = $name;
        $n = 1;
        while (isset($this->schemas[$final]) || isset($this->reservedIds[$final])) {
            $n++;
            $final = $name.'_'.$n;
        }

        // A reserved schema is always its own component — something references it — so a suffix here
        // means a genuine collision. Warn like the register path does.
        if ($final !== $name) {
            $this->diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-collision',
                message: sprintf('Two distinct schemas claimed component name "%s"; the second was hoisted as "%s".', $name, $final),
                help: 'Disambiguate with #[SchemaName] on one of the source classes.',
            );
        }

        $this->reservedIds[$final] = $schemaId;

        return $final;
    }

    /**
     * Register a reusable response component and return the `{"$ref": …}` array pointing at its
     * final name. Mirrors {@see reference()} for schemas.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public function referenceResponse(string $name, array $response): array
    {
        return ['$ref' => '#/components/responses/'.$this->registerResponse($name, $response)];
    }

    /**
     * Register a named response component, returning the final component name (suffixed on genuine
     * collision). A shared response like `ProblemUnauthenticated` dedupes to one hoist however many
     * operations reference it.
     *
     * @param  array<string, mixed>  $response
     */
    public function registerResponse(string $name, array $response): string
    {
        return $this->registerNamed($this->responses, $name, $response, 'responses');
    }

    /**
     * Register a security scheme an integration auto-configured; the returned name is what an
     * operation's `security` requirement references. Dedupes, so a shared `sanctum` hoists once.
     *
     * @param  array<string, mixed>  $definition
     */
    public function registerSecurityScheme(string $name, array $definition): string
    {
        return $this->registerNamed($this->securitySchemes, $name, $definition, 'security schemes');
    }

    /**
     * Hoist a named body into a component bucket, deduping a structurally-equal body and suffixing a
     * genuine collision. Schemas need name reservation for self-reference cycles, so they keep their
     * own variant in {@see registerSchema()}.
     *
     * @param  array<string, array<string, mixed>>  $bucket
     * @param  array<string, mixed>  $body
     */
    private function registerNamed(array &$bucket, string $name, array $body, string $kind): string
    {
        $name = self::sanitize($name);

        if (! isset($bucket[$name])) {
            $bucket[$name] = $body;

            return $name;
        }

        if (self::structurallyEqual($bucket[$name], $body)) {
            return $name;
        }

        $suffixed = $name;
        $n = 1;
        while (isset($bucket[$suffixed]) && ! self::structurallyEqual($bucket[$suffixed], $body)) {
            $n++;
            $suffixed = $name.'_'.$n;
        }

        if (! isset($bucket[$suffixed])) {
            $bucket[$suffixed] = $body;
            $this->diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-collision',
                message: sprintf('Two distinct %s claimed component name "%s"; the second was hoisted as "%s".', $kind, $name, $suffixed),
                help: 'Disambiguate the source of one of them.',
            );
        }

        return $suffixed;
    }

    /**
     * A restorable snapshot of the whole registry: a route that fails mid-pipeline after registering
     * components rolls back, so it leaves no orphaned components, diagnostics or leaked name
     * reservations behind.
     *
     * @return array{schemas: array<string, array<string, mixed>>, schemaIds: array<string, string>, reservedIds: array<string, string>, responses: array<string, array<string, mixed>>, securitySchemes: array<string, array<string, mixed>>, diagnostics: list<Diagnostic>}
     */
    public function snapshot(): array
    {
        return [
            'schemas' => $this->schemas,
            'schemaIds' => $this->schemaIds,
            'reservedIds' => $this->reservedIds,
            'responses' => $this->responses,
            'securitySchemes' => $this->securitySchemes,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * @param  array{schemas: array<string, array<string, mixed>>, schemaIds: array<string, string>, reservedIds: array<string, string>, responses: array<string, array<string, mixed>>, securitySchemes: array<string, array<string, mixed>>, diagnostics: list<Diagnostic>}  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->schemas = $snapshot['schemas'];
        $this->schemaIds = $snapshot['schemaIds'];
        $this->reservedIds = $snapshot['reservedIds'];
        $this->responses = $snapshot['responses'];
        $this->securitySchemes = $snapshot['securitySchemes'];
        $this->diagnostics = $snapshot['diagnostics'];
    }

    /**
     * Rolls only `components.responses` back to a snapshot's, leaving schemas and diagnostics alone.
     * For an extension that asks a mapper for a shape and then inlines the content itself: the shared
     * response the mapper registered would be an orphan (nothing `$ref`s it, and a warm cache — which
     * re-registers only what an operation references — would never bring it back), while the schemas
     * that inlined content points at must survive.
     *
     * @param  array{schemas: array<string, array<string, mixed>>, schemaIds: array<string, string>, reservedIds: array<string, string>, responses: array<string, array<string, mixed>>, securitySchemes: array<string, array<string, mixed>>, diagnostics: list<Diagnostic>}  $snapshot
     */
    public function restoreResponses(array $snapshot): void
    {
        $this->responses = $snapshot['responses'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * @return array<string, string>
     */
    public function schemaIds(): array
    {
        return $this->schemaIds;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function responses(): array
    {
        return $this->responses;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function securitySchemes(): array
    {
        return $this->securitySchemes;
    }

    /**
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Record a diagnostic raised while building components, e.g. a validation rule no transformer
     * handled. The assembler folds these into the document's diagnostic channel.
     */
    public function addDiagnostic(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    private static function sanitize(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '', $name);
        $clean = is_string($clean) ? $clean : '';

        return $clean === '' ? 'Schema' : $clean;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function structurallyEqual(array $a, array $b): bool
    {
        return Json::stable($a) === Json::stable($b);
    }
}
