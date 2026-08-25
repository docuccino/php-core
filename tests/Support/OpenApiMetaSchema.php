<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use Docuccino\Core\Emit\Formats;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * The OpenAPI meta-schemas, vendored per version, as an oracle over emitted bytes: give it a format id
 * and a decoded document and it names every place the document does not answer to its own spec.
 *
 * The vendored files are byte-exact as `spec.openapis.org` serves them at the dated (immutable) URIs in
 * {@see SCHEMAS} — all three verified SHA-256 identical to what those URIs answer, with zero external
 * `$ref`s in any of them, so nothing here resolves anything off disk or off the network.
 *
 * `SCHEMAS` therefore pins each file twice, and the two pins answer different questions.
 * The `published` id is IDENTITY: it catches a file swapped for another version's, and it is
 * checked against what the file DECLARES about itself. The `sha256` is CONTENT: it catches an
 * edit to a file that keeps declaring the same id, which is the whole of what an editor of 1,650
 * lines of third-party JSON would leave alone. The content pin is the load-bearing one, because
 * the oracle reads its own gate patterns out of these files ({@see keyGateFindings()}) — so one
 * flipped `false`, or one `^/` widened to `^.`, weakens the validator and the recovered gates
 * together, with nothing else in the suite the wiser.
 *
 * Neither pin is CURRENCY. A newer dated revision of any of the three would go unnoticed — the
 * `latest` alias 404s, so there is nothing offline to compare against — and catching that is a
 * deliberate manual step at upgrade time, not something the suite can see.
 *
 * Two normalisations stand between a vendored file and opis, and both are named where they are applied:
 * {@see dialect()} lifts 3.0's draft-04 to draft-07, and {@see opisWorkarounds()} rewrites the three
 * 2020-12 constructs opis 2.x gets wrong. Each is a documented, bounded edit — never a widening of what
 * a document may contain.
 *
 * @phpstan-type VendoredSchema array{file: string, published: string, sha256: string}
 * @phpstan-type KeyGates array{paths: list<string>, components: list<string>, responses: list<string>}
 */
final class OpenApiMetaSchema
{
    /**
     * Format id => the vendored file, the published id it must declare, and its SHA-256.
     *
     * Keyed by {@see Formats} id so a new OpenAPI version in that table has to bring a meta-schema with
     * it — `OpenApiMetaSchemaTest` fails when the two sets disagree, and again when either pin on a file
     * stops matching the bytes on disk.
     *
     * @var array<string, VendoredSchema>
     */
    public const array SCHEMAS = [
        'openapi-3.2' => [
            'file' => 'openapi-v3.2.schema.json',
            'published' => 'https://spec.openapis.org/oas/3.2/schema/2025-09-17',
            'sha256' => '0c9d74bf25f9b9388b2d81e421ef60fdefa9feffa94898dadfc501b342b3bfcc',
        ],
        'openapi-3.1' => [
            'file' => 'openapi-v3.1.schema.json',
            'published' => 'https://spec.openapis.org/oas/3.1/schema/2022-10-07',
            'sha256' => 'da01ba28852cac0de53893797cb8d1942bc3b05084f526dcc216717dec314ed0',
        ],
        'openapi-3.0' => [
            'file' => 'openapi-v3.0.schema.json',
            'published' => 'https://spec.openapis.org/oas/3.0/schema/2024-10-18',
            'sha256' => '2385f5bbb8c37878daae73baeabe7f34b2f022a4a8c049329ee61f71796f039c',
        ],
    ];

    /**
     * The path-item members that hold an Operation Object. `query` is 3.2's addition; naming one version's
     * method under another is caught by the meta-schema itself, so the union is safe to walk.
     *
     * @var list<string>
     */
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace', 'query'];

    /** @var array<string, Validator> */
    private static array $validators = [];

    /** @var array<string, KeyGates> */
    private static array $keyGates = [];

    /** The vendored file for $format. */
    public static function path(string $format): string
    {
        return dirname(__DIR__).'/Fixtures/'.self::row($format)['file'];
    }

    /** The dated, immutable URI the vendored file was fetched from. */
    public static function publishedId(string $format): string
    {
        return self::row($format)['published'];
    }

    /** The SHA-256 the vendored file's bytes must hash to. */
    public static function digest(string $format): string
    {
        return self::row($format)['sha256'];
    }

    /** @return VendoredSchema */
    private static function row(string $format): array
    {
        return self::SCHEMAS[$format] ?? throw new RuntimeException("No vendored meta-schema for format \"$format\".");
    }

    /** The vendored file, decoded to the object graph opis validates against. */
    public static function decode(string $format): mixed
    {
        return json_decode((string) file_get_contents(self::path($format)), flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Every way $instance fails $format's meta-schema, worst-first, one line each:
     * `<data pointer> <keyword>: <message> (schema <schema pointer>)`. Empty means valid.
     *
     * $instance must be an object graph — `json_decode` without `true`, or a kind-preserving YAML parse
     * ({@see EmittedDocument::parseYaml()}). Hand it an associative array and every map in the document
     * reads as a JSON array, which is the blindness this oracle exists to remove.
     *
     * The key gates {@see keyGateFindings()} recovers are folded in here, so every caller gets them.
     *
     * @return list<string>
     */
    public static function findings(string $format, mixed $instance): array
    {
        return [
            ...self::keyGateFindings($format, $instance),
            ...self::operationIdFindings($instance),
            ...SchemaFindings::of(self::validator($format), $instance, 'https://docuccino.test/'.$format.'.json'),
        ];
    }

    /**
     * Every `operationId` the document uses more than once. The spec requires them unique across the whole
     * API, and no meta-schema can say so — JSON Schema has no way to express uniqueness across positions —
     * so a duplicate validates clean at every version while a generated client loses a method to a name
     * collision. This is the assertion that sees it.
     *
     * @return list<string>
     */
    public static function operationIdFindings(mixed $instance): array
    {
        if (! $instance instanceof stdClass) {
            return [];
        }

        $seen = [];

        foreach (self::operations($instance) as $pointer => $operation) {
            $id = $operation->operationId ?? null;

            if (is_string($id)) {
                $seen[$id][] = $pointer;
            }
        }

        $findings = [];

        foreach ($seen as $id => $pointers) {
            if (count($pointers) > 1) {
                sort($pointers);
                $findings[] = sprintf('%s operationId: "%s" is used by %s', $pointers[0], $id, implode(', ', $pointers));
            }
        }

        sort($findings);

        return $findings;
    }

    /**
     * The `patternProperties` KEY GATES the 3.1/3.2 meta-schemas enforce only through
     * `unevaluatedProperties: false` — which is off, for the opis defects named in {@see self::validator()}.
     * Turning that keyword off silently took all 28 of those sites with it, so a `paths` key not starting
     * with `/` and a response keyed `twohundred` both validated clean. This walks the three gates back on
     * directly, which needs no validator and no `$ref` resolution.
     *
     * Patterns are READ OUT of the vendored file, never restated here, so a schema whose gate changes
     * moves this with it — and the digest in {@see SCHEMAS} is what stops that reading from being
     * redirected by an edit to the file. 3.0 needs none: it carries no `unevaluatedProperties` at all,
     * so its 43 gates were never disabled.
     *
     * @return list<string>
     */
    public static function keyGateFindings(string $format, mixed $instance): array
    {
        if (! $instance instanceof stdClass || self::isDraft04($format)) {
            return [];
        }

        $gates = self::keyGates($format);
        $findings = [];

        foreach (['paths', 'components'] as $member) {
            $findings = [...$findings, ...self::gateKeys($instance->{$member} ?? null, $gates[$member], '/'.$member, $member)];
        }

        foreach (self::operations($instance) as $pointer => $operation) {
            $findings = [
                ...$findings,
                ...self::gateKeys($operation->responses ?? null, $gates['responses'], $pointer.'/responses', 'responses'),
            ];
        }

        sort($findings);

        return $findings;
    }

    /**
     * Every key of $map that matches none of $patterns, as findings.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private static function gateKeys(mixed $map, array $patterns, string $pointer, string $gate): array
    {
        if (! $map instanceof stdClass) {
            return [];
        }

        $findings = [];

        foreach (array_keys(get_object_vars($map)) as $key) {
            foreach ($patterns as $pattern) {
                if (preg_match('~'.str_replace('~', '\~', $pattern).'~', (string) $key) === 1) {
                    continue 2;
                }
            }

            $findings[] = sprintf(
                '%s patternProperties: The key "%s" matches none of %s (schema /$defs/%s)',
                $pointer.'/'.self::escape((string) $key),
                $key,
                implode(', ', $patterns),
                $gate,
            );
        }

        return $findings;
    }

    /**
     * The gate patterns for `paths`, `components` and an operation's `responses`, read from the vendored
     * file. A declared `properties` key (`responses`' `default`) is an exact-match alternative, and every
     * one of the three `$ref`s the specification-extensions schema, so `^x-` is always allowed.
     *
     * Cached per format for the same reason {@see self::validator()} is: this runs once per document and
     * the read behind it decodes a 39KB file.
     *
     * @return KeyGates
     */
    private static function keyGates(string $format): array
    {
        if (isset(self::$keyGates[$format])) {
            return self::$keyGates[$format];
        }

        $defs = self::decode($format)->{'$defs'};

        $patterns = static function (string $def) use ($defs): array {
            $node = $defs->{$def};

            $found = array_keys(get_object_vars($node->patternProperties));

            foreach (array_keys(get_object_vars($node->properties ?? new stdClass)) as $literal) {
                $found[] = '^'.preg_quote((string) $literal, '~').'$';
            }

            return [...$found, ...array_keys(get_object_vars($defs->{'specification-extensions'}->patternProperties))];
        };

        return self::$keyGates[$format] = [
            'paths' => $patterns('paths'),
            'components' => $patterns('components'),
            'responses' => $patterns('responses'),
        ];
    }

    /**
     * Every Operation Object in the document, by JSON pointer. Found structurally — path items live under
     * `paths`, `webhooks` and `components.pathItems`, and each operation's `callbacks` carry more — so
     * nothing here resolves a `$ref`, and a map merely NAMED `responses` inside a Schema Object is never
     * mistaken for a Responses Object.
     *
     * @return array<string, stdClass>
     */
    private static function operations(stdClass $instance): array
    {
        $components = $instance->components ?? null;

        $containers = [
            '/paths' => $instance->paths ?? null,
            '/webhooks' => $instance->webhooks ?? null,
            '/components/pathItems' => $components instanceof stdClass ? ($components->pathItems ?? null) : null,
        ];

        $operations = [];

        while ($containers !== []) {
            $pathItems = [];

            foreach ($containers as $pointer => $container) {
                if (! $container instanceof stdClass) {
                    continue;
                }

                foreach (get_object_vars($container) as $name => $item) {
                    if ($item instanceof stdClass) {
                        $pathItems[$pointer.'/'.self::escape((string) $name)] = $item;
                    }
                }
            }

            $containers = [];

            foreach ($pathItems as $pointer => $item) {
                foreach (self::METHODS as $method) {
                    $operation = $item->{$method} ?? null;

                    if (! $operation instanceof stdClass) {
                        continue;
                    }

                    $operations[$pointer.'/'.$method] = $operation;

                    // A callback maps a runtime EXPRESSION to a path item, which is the same shape as the
                    // containers above — so each one goes back through the loop and its operations count.
                    foreach (get_object_vars($operation->callbacks ?? new stdClass) as $name => $callback) {
                        if ($callback instanceof stdClass) {
                            $containers[$pointer.'/'.$method.'/callbacks/'.self::escape((string) $name)] = $callback;
                        }
                    }
                }

                // 3.2 lets a path item carry operations under names of its own choosing.
                foreach (get_object_vars($item->additionalOperations ?? new stdClass) as $method => $operation) {
                    if ($operation instanceof stdClass) {
                        $operations[$pointer.'/additionalOperations/'.self::escape((string) $method)] = $operation;
                    }
                }
            }
        }

        return $operations;
    }

    /** Whether $format's vendored file is draft-04 (3.0), which needs no key-gate recovery. */
    private static function isDraft04(string $format): bool
    {
        return str_starts_with(self::publishedId($format), 'https://spec.openapis.org/oas/3.0/');
    }

    /** A JSON pointer token, escaped. */
    private static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    /** The parsed, cached validator for $format. Parsing a 39KB meta-schema per assertion is the cost. */
    private static function validator(string $format): Validator
    {
        if (isset(self::$validators[$format])) {
            return self::$validators[$format];
        }

        $schema = self::decode($format);
        $schema = str_starts_with(self::publishedId($format), 'https://spec.openapis.org/oas/3.0/')
            ? self::dialect($schema)
            : self::opisWorkarounds($schema);

        $validator = new Validator;
        $validator->setMaxErrors(50);

        // An oracle may not touch what it reads. opis applies schema `default`s INTO the instance, so
        // validating a 3.2 document silently gave it a `jsonSchemaDialect` and a `servers` it never
        // emitted — and the next assertion over the same graph then compared against the mutation.
        $validator->parser()->setOption('allowDefaults', false);

        // opis's own extensions to JSON Schema ($filters, $map, $vars, slots, pragmas, `$data`
        // references). A vendored third-party schema is plain JSON Schema and must be read as such.
        foreach (['allowFilters', 'allowMappers', 'allowTemplates', 'allowGlobals', 'allowSlots', 'allowPragmas', 'allowDataKeyword', 'allowKeywordValidators'] as $extension) {
            $validator->parser()->setOption($extension, false);
        }

        // `format` is an annotation in 2020-12's default vocabulary and OpenAPI declares no
        // format-assertion vocabulary, so asserting it reads a templated server url
        // (`https://api.example.com/{version}`) as a broken uri-reference. opis asserts by default.
        $validator->parser()->setOption('allowFormats', false);

        // opis's `unevaluatedProperties` does not collect annotations produced inside
        // `dependentSchemas` or `if`/`then`, and reports properties the instance does not even carry as
        // unevaluated — a bare `{name, in, schema}` parameter comes back "unevaluated: explode,
        // allowReserved, allowEmptyValue".
        //
        // Off costs MORE than "no unrecognised member". 3.1 and 3.2 spell `unevaluatedProperties: false`
        // at 28 sites each, and those are the only thing enforcing their `patternProperties` KEY gates —
        // so a `paths` key not starting with `/`, a response keyed `twohundred` and a misspelled
        // `info.versionn` all validate clean with it off. 3.0 is unaffected (no such keyword; its gates
        // close with `additionalProperties`), which is why it rejects every one of those.
        // {@see keyGateFindings()} walks the three gates back on directly; what stays lost is the
        // unrecognised-member check everywhere else, and everything inside a Schema Object, which the
        // 3.1/3.2 meta-schemas leave unconstrained regardless. `OpenApiUnevaluatedScopeTest` is the
        // measured matrix of exactly that, row by row.
        $validator->parser()->setOption('allowUnevaluated', false);

        $validator->resolver()?->registerRaw($schema, 'https://docuccino.test/'.$format.'.json');

        return self::$validators[$format] = $validator;
    }

    /**
     * OpenAPI 3.0's meta-schema is published as draft-04, which opis does not parse. Lifts the dialect
     * WITHOUT touching anything that constrains an instance: the draft-04 `id` anchor is dropped (every
     * `$ref` in the file is a `#/definitions/…` pointer that resolves without it) and the dialect is
     * redeclared. Keys inside a `properties`, `definitions` or `patternProperties` map are names, not
     * keywords, so a property genuinely called `id` survives. Draft-04's boolean
     * `exclusiveMinimum`/`exclusiveMaximum` needs nothing: opis reads it under
     * `allowExclusiveMinMaxAsBool`, which is on by default.
     */
    private static function dialect(mixed $node, bool $inMap = false): mixed
    {
        if (is_array($node)) {
            return array_map(static fn (mixed $v): mixed => self::dialect($v), $node);
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        $out = new stdClass;
        foreach (get_object_vars($node) as $key => $value) {
            if (! $inMap && ($key === 'id' || $key === '$schema') && is_string($value)) {
                continue;
            }

            $out->{$key} = self::dialect($value, self::opensNameMap($inMap, $key));
        }

        if (! $inMap && isset($node->{'$schema'})) {
            $out->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
        }

        return $out;
    }

    /**
     * The 3.1 and 3.2 meta-schemas are draft 2020-12 and opis 2.x mis-evaluates two of the constructs
     * they lean on, so both are rewritten to the nearest form it reads correctly:
     *
     * - `$dynamicRef: "#meta"` resolves, in opis, to the schema resource ROOT rather than the lexical
     *   `$dynamicAnchor`, so every Schema Object position in the document gets validated against the
     *   OpenAPI Object itself ("required properties (openapi, info) are missing"). Each file carries
     *   exactly one `$dynamicAnchor: "meta"`, at `#/$defs/schema`, and nothing here extends the dialect,
     *   so the static `$ref` is what the dynamic one is specified to resolve to.
     * - `contains` alongside `minContains: 0` still demands one match (`ContainsKeyword` errors on
     *   `$valid === 0` after the `minContains` check has already passed), which fails every document
     *   whose parameter list holds no `querystring` parameter. Only `minContains` has to go; `maxContains`
     *   is collateral, and its cost is that TWO `querystring` parameters validate clean at 3.2 where the
     *   spec caps them at one. Nothing in the product can mint such a parameter today, so the loss is
     *   unreachable rather than harmless — measured either way, in `OpenApiUnevaluatedScopeTest`, beside
     *   the row proving the mutual exclusion of `query` and `querystring` is NOT lost with it (that rides
     *   a `not`/`allOf` opis reads correctly), which is what bounds the loss to the cap alone.
     */
    private static function opisWorkarounds(mixed $node, bool $inMap = false): mixed
    {
        if (is_array($node)) {
            return array_map(static fn (mixed $v): mixed => self::opisWorkarounds($v), $node);
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        $vars = get_object_vars($node);
        $unbounded = ! $inMap && ($vars['minContains'] ?? null) === 0;

        $out = new stdClass;
        foreach ($vars as $key => $value) {
            if (! $inMap && $key === '$dynamicAnchor') {
                continue;
            }

            if (! $inMap && $key === '$dynamicRef' && $value === '#meta') {
                $out->{'$ref'} = '#/$defs/schema';

                continue;
            }

            if ($unbounded && in_array($key, ['contains', 'minContains', 'maxContains'], true)) {
                continue;
            }

            $out->{$key} = self::opisWorkarounds($value, self::opensNameMap($inMap, $key));
        }

        return $out;
    }

    /** Whether $key's value is a map of NAMES (so its keys are never keywords), given where we are. */
    private static function opensNameMap(bool $inMap, string $key): bool
    {
        return ! $inMap && in_array($key, ['properties', 'definitions', 'patternProperties', '$defs'], true);
    }
}
