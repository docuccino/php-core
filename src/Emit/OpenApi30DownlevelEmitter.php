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
 * Downlevels to OpenAPI 3.0.4 for toolchains pinned to 3.0 (AWS API Gateway, older codegen and
 * validators). It chains off {@see OpenApi31DownlevelEmitter}, so the 3.2-only constructs are
 * already gone and this emitter only has to answer 3.0's own restrictions — chiefly its schema
 * dialect, a draft-4-shaped subset of JSON Schema 2020-12.
 *
 * Document level: `webhooks`, `components.pathItems`, `info.summary` and `mutualTLS` security
 * schemes have no 3.0 home; `info.license.identifier` becomes an SPDX URL when there is no `url`.
 * Schema level: nullable type-arrays become `nullable: true`, `const` becomes a single-value `enum`,
 * schema `examples` become `example`, numeric exclusive bounds become the boolean form, `$ref`
 * siblings hoist into an `allOf` wrapper, and {@see UNSUPPORTED_SCHEMA_KEYWORDS} is dropped.
 *
 * Every lossy step warns into an {@see EmitReport} naming the JSON pointer it happened at, so a
 * 3.0 export states what it could not carry instead of quietly shipping a weaker contract.
 *
 * @internal
 */
final readonly class OpenApi30DownlevelEmitter implements Emitter
{
    /** The latest 3.0 patch release; 3.0.x are editorial revisions of one spec. */
    private const string VERSION = '3.0.4';

    private const string SPDX_BASE = 'https://spdx.org/licenses/';

    /** 2020-12 schema keywords 3.0 cannot express at all: dropped, each with a note naming it. */
    public const array UNSUPPORTED_SCHEMA_KEYWORDS = [
        '$anchor',
        '$defs',
        '$id',
        '$schema',
        'contains',
        'contentMediaType',
        'dependentRequired',
        'dependentSchemas',
        'else',
        'if',
        'maxContains',
        'minContains',
        'patternProperties',
        'prefixItems',
        'propertyNames',
        'then',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];

    /** Annotations with no consumer-visible meaning — dropped without a note. */
    public const array SILENT_SCHEMA_KEYWORDS = ['$comment'];

    /** Keywords whose value is another schema. */
    private const array SUBSCHEMA_KEYWORDS = ['items', 'not', 'additionalProperties'];

    /** Keywords whose value is a list of schemas. */
    private const array SUBSCHEMA_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];

    /** Members whose value is user data the schema walk must not descend into. */
    private const array OPAQUE_MEMBERS = ['const', 'default', 'enum', 'example', 'examples'];

    public function __construct(
        private OpenApi31DownlevelEmitter $oas31 = new OpenApi31DownlevelEmitter,
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
        private YamlSerializer $yaml = new YamlSerializer,
    ) {}

    public function format(): string
    {
        return 'openapi-3.0';
    }

    public function emit(UirDocument $document, EmitOptions $options = new EmitOptions): string
    {
        return $this->emitWithReport($document, $options)->output;
    }

    public function emitWithReport(UirDocument $document, EmitOptions $options = new EmitOptions): EmitResult
    {
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        $array = $this->downlevel($this->oas31->toOpenApiArray($document, $diagnostics, $options), $diagnostics);
        $canonical = $this->canonicalizer->canonicalize($array);

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
        $array['openapi'] = self::VERSION;

        // 3.0 pins its own dialect; the member itself is 3.1+.
        unset($array['jsonSchemaDialect']);

        $array = $this->downlevelInfo($array, $diagnostics);
        $array = $this->dropWebhooks($array, $diagnostics);
        $array = $this->downlevelComponents($array, $diagnostics);

        /** @var array<string, mixed> $walked */
        $walked = $this->walk($array, '#', $diagnostics);

        return $walked;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelInfo(array $array, array &$diagnostics): array
    {
        if (! is_array($array['info'] ?? null)) {
            return $array;
        }

        $info = Arr::stringKeyed($array['info']);

        if (isset($info['summary'])) {
            unset($info['summary']);
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.info-summary',
                message: 'Dropped `info.summary` (#/info/summary), which OpenAPI 3.0 does not define.',
                help: 'Lead `info.description` with the same sentence if 3.0 consumers need it.',
            );
        }

        if (is_array($info['license'] ?? null)) {
            $info['license'] = $this->downlevelLicense(Arr::stringKeyed($info['license']), $diagnostics);
        }

        $array['info'] = $info;

        return $array;
    }

    /**
     * 3.0's license object carries only `name` and `url`, so an SPDX `identifier` becomes the SPDX
     * URL when there is no `url` to keep, and is dropped when there is.
     *
     * @param  array<string, mixed>  $license
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelLicense(array $license, array &$diagnostics): array
    {
        $identifier = $license['identifier'] ?? null;
        if (! is_string($identifier)) {
            return $license;
        }

        unset($license['identifier']);

        if (isset($license['url'])) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.license-identifier',
                message: sprintf('Dropped the SPDX `info.license.identifier` "%s"; OpenAPI 3.0 keeps only the existing `url`.', $identifier),
            );

            return $license;
        }

        $license['url'] = self::SPDX_BASE.$identifier;

        $diagnostics[] = new Diagnostic(
            severity: Severity::Info,
            code: 'downlevel.license-identifier',
            message: sprintf('Rewrote the SPDX `info.license.identifier` "%s" as `info.license.url`, which is how OpenAPI 3.0 names a license.', $identifier),
        );

        return $license;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function dropWebhooks(array $array, array &$diagnostics): array
    {
        if (! isset($array['webhooks'])) {
            return $array;
        }

        unset($array['webhooks']);

        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'downlevel.webhooks',
            message: 'Dropped `webhooks` (#/webhooks), which OpenAPI 3.0 does not define.',
            help: 'Keep the 3.1 or 3.2 artifact for consumers that need the webhook contract.',
        );

        return $array;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelComponents(array $array, array &$diagnostics): array
    {
        if (! is_array($array['components'] ?? null)) {
            return $array;
        }

        $components = Arr::stringKeyed($array['components']);

        if (isset($components['pathItems'])) {
            unset($components['pathItems']);
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.component-path-items',
                message: 'Dropped `components.pathItems` (#/components/pathItems), which OpenAPI 3.0 does not define.',
                help: 'Inline the path item at each use site if 3.0 consumers need it.',
            );
        }

        $dropped = [];
        if (is_array($components['securitySchemes'] ?? null)) {
            [$schemes, $dropped] = $this->downlevelSecuritySchemes(Arr::stringKeyed($components['securitySchemes']), $diagnostics);
            $components['securitySchemes'] = $schemes;
        }

        $array['components'] = $components;

        if ($dropped === []) {
            return $array;
        }

        return Arr::stringKeyed($this->dropSecurityRequirements($array, $dropped));
    }

    /**
     * @param  array<string, mixed>  $schemes
     * @param  list<Diagnostic>  $diagnostics
     * @return array{array<string, mixed>, list<string>}
     */
    private function downlevelSecuritySchemes(array $schemes, array &$diagnostics): array
    {
        $dropped = [];

        foreach ($schemes as $name => $scheme) {
            if (! is_array($scheme) || ($scheme['type'] ?? null) !== 'mutualTLS') {
                continue;
            }

            unset($schemes[$name]);
            $dropped[] = $name;

            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.mutual-tls',
                message: sprintf('Dropped the `mutualTLS` security scheme "%s" (#/components/securitySchemes/%s), which OpenAPI 3.0 does not define, along with every requirement naming it.', $name, $name),
                help: 'Document mutual TLS in prose for 3.0 consumers, or keep the 3.1 artifact.',
            );
        }

        return [$schemes, $dropped];
    }

    /**
     * Requirements naming a dropped scheme would dangle, so they go with it. An emptied requirement
     * is removed rather than left as `{}` — that would read as "no security required".
     *
     * @param  array<mixed, mixed>  $node
     * @param  list<string>  $dropped
     * @return array<mixed, mixed>
     */
    private function dropSecurityRequirements(array $node, array $dropped): array
    {
        foreach ($node as $key => $value) {
            $key = (string) $key;

            if (! is_array($value) || str_starts_with($key, 'x-') || in_array($key, self::OPAQUE_MEMBERS, true)) {
                continue;
            }

            if ($key === 'security') {
                $requirements = [];
                foreach ($value as $requirement) {
                    $requirement = is_array($requirement) ? array_diff_key($requirement, array_flip($dropped)) : $requirement;

                    if ($requirement !== []) {
                        $requirements[] = $requirement;
                    }
                }

                if ($requirements === []) {
                    unset($node[$key]);

                    continue;
                }

                $node[$key] = $requirements;

                continue;
            }

            $node[$key] = $this->dropSecurityRequirements($value, $dropped);
        }

        return $node;
    }

    /**
     * Generic descent looking for schema positions: a `schema` member anywhere, and every entry of
     * `components.schemas`. User data (`example`, `default`, …) and `x-*` members pass untouched.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    private function walk(mixed $node, string $pointer, array &$diagnostics): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $out = [];

        foreach ($node as $key => $value) {
            $key = (string) $key;
            $child = self::pointer($pointer, $key);

            $out[$key] = match (true) {
                str_starts_with($key, 'x-'), in_array($key, self::OPAQUE_MEMBERS, true) => $value,
                $key === 'schema' && is_array($value) => $this->schema(Arr::stringKeyed($value), $child, $diagnostics),
                $key === 'schemas' && $pointer === '#/components' && is_array($value) => $this->schemaMap($value, $child, $diagnostics),
                default => $this->walk($value, $child, $diagnostics),
            };
        }

        return array_is_list($node) ? array_values($out) : $out;
    }

    /** A child JSON Pointer, with the RFC 6901 escapes a path template needs. */
    private static function pointer(string $parent, string $token): string
    {
        return $parent.'/'.str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    /**
     * @param  array<mixed, mixed>  $map
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function schemaMap(array $map, string $pointer, array &$diagnostics): array
    {
        $out = [];

        foreach ($map as $name => $schema) {
            $name = (string) $name;
            $out[$name] = is_array($schema)
                ? $this->schema(Arr::stringKeyed($schema), self::pointer($pointer, $name), $diagnostics)
                : $schema;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function schema(array $schema, string $pointer, array &$diagnostics): array
    {
        $schema = $this->hoistRefSiblings($schema, $pointer, $diagnostics);
        $schema = $this->downlevelType($schema, $pointer, $diagnostics);
        $schema = $this->downlevelNullBranches($schema, $pointer, $diagnostics);
        $schema = $this->downlevelKeywords($schema, $pointer, $diagnostics);

        return $this->recurse($schema, $pointer, $diagnostics);
    }

    /**
     * 3.0 ignores anything beside a `$ref`, so siblings move out to an `allOf` wrapper — lossless,
     * and the shape every 3.0 toolchain reads.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function hoistRefSiblings(array $schema, string $pointer, array &$diagnostics): array
    {
        $ref = $schema['$ref'] ?? null;
        if (! is_string($ref) || count($schema) === 1) {
            return $schema;
        }

        unset($schema['$ref']);
        $existing = is_array($schema['allOf'] ?? null) ? array_values($schema['allOf']) : [];
        $schema['allOf'] = [['$ref' => $ref], ...$existing];

        $diagnostics[] = new Diagnostic(
            severity: Severity::Info,
            code: 'downlevel.ref-siblings',
            message: sprintf('Hoisted the members beside `$ref` at %s into an `allOf` wrapper; OpenAPI 3.0 ignores a `$ref` sibling.', $pointer),
        );

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelType(array $schema, string $pointer, array &$diagnostics): array
    {
        $type = $schema['type'] ?? null;

        if ($type === 'null' || $type === ['null']) {
            unset($schema['type']);
            $schema['nullable'] = true;

            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.null-type',
                message: sprintf('Rewrote the null-only type at %s as an untyped `nullable: true`; OpenAPI 3.0 has no `null` type.', $pointer),
            );

            return $schema;
        }

        if (! is_array($type)) {
            return $schema;
        }

        $members = array_values(array_filter($type, static fn (mixed $t): bool => $t !== 'null'));
        unset($schema['type']);

        if (count($type) !== count($members)) {
            $schema['nullable'] = true;
        }

        if (count($members) <= 1) {
            if ($members !== []) {
                $schema['type'] = $members[0];
            }

            return $schema;
        }

        return $this->downlevelMultiType($schema, $members, $pointer, $diagnostics);
    }

    /**
     * More than one non-null type has no 3.0 spelling. An `anyOf` of single-type branches says the
     * same thing where the schema composes nothing yet; otherwise the type constraint is dropped —
     * the loosest sound reading.
     *
     * @param  array<string, mixed>  $schema
     * @param  non-empty-list<mixed>  $members
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelMultiType(array $schema, array $members, string $pointer, array &$diagnostics): array
    {
        $composes = isset($schema['anyOf']) || isset($schema['oneOf']);

        if (! $composes) {
            $schema['anyOf'] = array_map(static fn (mixed $type): array => ['type' => $type], $members);
        }

        $diagnostics[] = new Diagnostic(
            severity: $composes ? Severity::Warning : Severity::Info,
            code: 'downlevel.multi-type',
            message: $composes
                ? sprintf('Dropped the multi-type `type` at %s; OpenAPI 3.0 allows one type and the schema already composes.', $pointer)
                : sprintf('Rewrote the multi-type `type` at %s as an `anyOf` of single-type branches; OpenAPI 3.0 allows one type.', $pointer),
        );

        return $schema;
    }

    /**
     * A `{type: null}` branch is how 2020-12 spells nullable next to a `$ref` or a union; in 3.0
     * that is `nullable: true` on the parent, with a lone surviving branch folded back in.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelNullBranches(array $schema, string $pointer, array &$diagnostics): array
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;
            if (! is_array($branches)) {
                continue;
            }

            $kept = array_values(array_filter($branches, static fn (mixed $b): bool => $b !== ['type' => 'null']));
            if (count($kept) === count($branches)) {
                continue;
            }

            $schema[$keyword] = $kept;
            $schema['nullable'] = true;

            if (count($kept) !== 1) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Info,
                    code: 'downlevel.nullable-composition',
                    message: sprintf('Moved the `{type: null}` branch at %s/%s onto the parent as `nullable: true`, which OpenAPI 3.0 reads loosely beside a composition.', $pointer, $keyword),
                );

                continue;
            }

            unset($schema[$keyword]);
            $schema = $this->foldBranch($schema, Arr::stringKeyed(is_array($kept[0]) ? $kept[0] : []));
        }

        return $schema;
    }

    /**
     * The one surviving branch of a nullable union. A `$ref` can carry no `nullable` sibling, so it
     * becomes an `allOf` wrapper; anything else merges in, leaving the parent's own members alone.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $branch
     * @return array<string, mixed>
     */
    private function foldBranch(array $schema, array $branch): array
    {
        $ref = $branch['$ref'] ?? null;

        if (is_string($ref) && count($branch) === 1) {
            $existing = is_array($schema['allOf'] ?? null) ? array_values($schema['allOf']) : [];
            $schema['allOf'] = [['$ref' => $ref], ...$existing];

            return $schema;
        }

        return $schema + $branch;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelKeywords(array $schema, string $pointer, array &$diagnostics): array
    {
        $schema = $this->downlevelConst($schema, $pointer, $diagnostics);
        $schema = $this->downlevelExamples($schema, $pointer, $diagnostics);
        $schema = $this->downlevelExclusiveBounds($schema, $pointer, $diagnostics);
        $schema = $this->downlevelContentEncoding($schema, $pointer, $diagnostics);

        foreach (self::UNSUPPORTED_SCHEMA_KEYWORDS as $keyword) {
            if (! array_key_exists($keyword, $schema)) {
                continue;
            }

            unset($schema[$keyword]);
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.unsupported-keyword',
                message: sprintf('Dropped the schema keyword `%s` at %s, which OpenAPI 3.0 does not define.', $keyword, $pointer),
                help: 'Keep the 3.1 or 3.2 artifact for consumers that validate against the full constraint.',
            );
        }

        foreach (self::SILENT_SCHEMA_KEYWORDS as $keyword) {
            unset($schema[$keyword]);
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelConst(array $schema, string $pointer, array &$diagnostics): array
    {
        if (! array_key_exists('const', $schema)) {
            return $schema;
        }

        $const = $schema['const'];
        unset($schema['const']);

        if (! array_key_exists('enum', $schema)) {
            $schema['enum'] = [$const];
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Info,
            code: 'downlevel.const',
            message: sprintf('Rewrote `const` at %s as a single-value `enum`, which is how OpenAPI 3.0 pins a value.', $pointer),
        );

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelExamples(array $schema, string $pointer, array &$diagnostics): array
    {
        $examples = $schema['examples'] ?? null;
        if (! is_array($examples)) {
            return $schema;
        }

        unset($schema['examples']);

        $first = array_values($examples)[0] ?? null;
        $kept = array_is_list($examples) && $examples !== [] && ! array_key_exists('example', $schema);

        if ($kept) {
            $schema['example'] = $first;
        }

        $diagnostics[] = new Diagnostic(
            severity: $kept ? Severity::Info : Severity::Warning,
            code: 'downlevel.schema-examples',
            message: $kept
                ? sprintf('Kept the first of the schema `examples` at %s as `example`; OpenAPI 3.0 carries a single one.', $pointer)
                : sprintf('Dropped the schema `examples` at %s, which OpenAPI 3.0 does not define.', $pointer),
        );

        return $schema;
    }

    /**
     * 2020-12's exclusive bounds are numbers; 3.0's are booleans qualifying `minimum`/`maximum`. A
     * schema already carrying the inclusive bound cannot hold both, so the exclusive one goes.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelExclusiveBounds(array $schema, string $pointer, array &$diagnostics): array
    {
        foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $keyword => $bound) {
            $value = $schema[$keyword] ?? null;
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            if (array_key_exists($bound, $schema)) {
                unset($schema[$keyword]);
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'downlevel.exclusive-bound',
                    message: sprintf('Dropped the numeric `%s` at %s; OpenAPI 3.0 spells it as a boolean on `%s`, which is already taken.', $keyword, $pointer, $bound),
                );

                continue;
            }

            $schema[$bound] = $value;
            $schema[$keyword] = true;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelContentEncoding(array $schema, string $pointer, array &$diagnostics): array
    {
        $encoding = $schema['contentEncoding'] ?? null;
        if ($encoding === null) {
            return $schema;
        }

        unset($schema['contentEncoding']);

        $asByte = $encoding === 'base64' && ! array_key_exists('format', $schema);
        if ($asByte) {
            $schema['format'] = 'byte';
        }

        $diagnostics[] = new Diagnostic(
            severity: $asByte ? Severity::Info : Severity::Warning,
            code: 'downlevel.content-encoding',
            message: $asByte
                ? sprintf('Rewrote `contentEncoding: base64` at %s as `format: byte`, which is how OpenAPI 3.0 spells it.', $pointer)
                : sprintf('Dropped `contentEncoding` at %s, which OpenAPI 3.0 does not define.', $pointer),
        );

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function recurse(array $schema, string $pointer, array &$diagnostics): array
    {
        foreach (self::SUBSCHEMA_KEYWORDS as $keyword) {
            $subschema = $schema[$keyword] ?? null;
            if (is_array($subschema)) {
                $schema[$keyword] = $this->schema(Arr::stringKeyed($subschema), $pointer.'/'.$keyword, $diagnostics);
            }
        }

        foreach (self::SUBSCHEMA_LIST_KEYWORDS as $keyword) {
            $list = $schema[$keyword] ?? null;
            if (! is_array($list)) {
                continue;
            }

            $branches = [];
            foreach (array_values($list) as $index => $branch) {
                $branches[] = is_array($branch)
                    ? $this->schema(Arr::stringKeyed($branch), $pointer.'/'.$keyword.'/'.$index, $diagnostics)
                    : $branch;
            }

            $schema[$keyword] = $branches;
        }

        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            $schema['properties'] = $this->schemaMap($properties, $pointer.'/properties', $diagnostics);
        }

        return $schema;
    }
}
