<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Support\Arr;

/**
 * Downlevels to OpenAPI 3.0.4 for toolchains pinned to 3.0 (AWS API Gateway, older codegen and
 * validators). It chains off {@see OpenApi31DownlevelEmitter}, so the 3.2-only constructs are
 * already gone and this emitter only has to answer 3.0's own restrictions — chiefly its schema
 * dialect, a draft-4-shaped subset of JSON Schema 2020-12.
 *
 * What it drops and what it rewrites, member by member, is the reference table on the site's first-export
 * page; the two internal facts are that an operation documenting no `responses` gains a placeholder
 * ({@see UNDESCRIBED_RESPONSE}, since 3.0 requires the member 3.1 made optional), and that which members
 * carry subschemas at all is read off {@see SchemaKeywords} rather than listed again here.
 *
 * The walk is positional throughout ({@see member()}): what a node IS follows from where it sits, never
 * from how its key is spelled. `responses.default` is a Response Object whose key reads like a schema
 * keyword, a component may be named `example`, and a path item is one because a path-item map holds it.
 * The rule runs the other way too — a key means something only where it is a fixed field, so a Link
 * Object's `parameters` and a Security Requirement Object's members are read as what the application
 * wrote however they are spelled.
 *
 * Every step that changes what a consumer reads goes into an {@see EmitReport} at the JSON pointer it
 * happened at, Warning where a contract is lost and Info where it survives in another shape. Hence one
 * code, `downlevel.ref-siblings`, for both answers at a `$ref` — a schema's siblings moving into an
 * `allOf`, and a reference's own prose coming off — with the message saying which.
 *
 * @phpstan-type Position self::FIELDS|self::PATH_ITEM|self::NAMES|self::PATH_ITEMS|self::CALLBACKS|self::LINKS|self::LINK|self::SCHEMA|self::SCHEMA_MAP
 * @phpstan-type Removed array{pathItems: array<string, mixed>, securitySchemes: list<string>, inlining: list<string>}
 * @phpstan-type PathItemChain array{item: array<string, mixed>|null, chain: list<string>, cycle: bool}
 *
 * @internal
 */
final readonly class OpenApi30DownlevelEmitter implements ReportingEmitter
{
    /** The latest 3.0 patch release; 3.0.x are editorial revisions of one spec. */
    private const string VERSION = '3.0.4';

    private const string SPDX_BASE = 'https://spdx.org/licenses/';

    /**
     * 2020-12 schema keywords 3.0 cannot express at all: dropped, each with a note naming it. 3.0's
     * Schema Object is CLOSED — every member bar `^x-` is enumerated — so a keyword absent both from
     * 3.0 and from this list fails 3.0's own gate whatever value it carries. A guard holds the two
     * against the vendored meta-schema rather than against anybody's memory of the spec.
     */
    public const array UNSUPPORTED_SCHEMA_KEYWORDS = [
        '$anchor',
        '$defs',
        '$id',
        '$schema',
        'additionalItems',
        'contains',
        'contentMediaType',
        'contentSchema',
        'definitions',
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

    /**
     * The keywords 3.0's Schema Object does not define and this emitter answers for anyway, so their
     * absence from 3.0 is no reason to drop them: {@see downlevelConst()}, {@see downlevelExamples()}
     * and {@see downlevelContentEncoding()} rewrite the first three, and a lone `$ref` is a Reference
     * Object rather than a Schema Object ({@see hoistRefSiblings()} moves anything standing beside it).
     * Stated for the guard that reads 3.0's closed member set — nothing here branches on it.
     */
    public const array HANDLED_SCHEMA_KEYWORDS = ['$ref', 'const', 'contentEncoding', 'examples'];

    /** The one position 3.0 spells with a boolean, exactly as draft-4 did ({@see subschema()}). */
    private const string BOOLEAN_SCHEMA_KEYWORD = 'additionalProperties';

    /**
     * Fixed fields whose value is an arbitrary JSON value the document carries rather than more document:
     * a Media Type, Parameter or Header Object's `example`, and an Example Object's `value`. Read only at
     * a fixed-field position — a response, a header or a component may be NAMED `example`, and that name
     * describes nothing about what it holds.
     */
    private const array USER_DATA_FIELDS = ['example', 'value'];

    /**
     * A Link Object's two fixed fields whose value is the application's rather than more document: its
     * `parameters` is `Map[string, Any | {expression}]` and its `requestBody` is `Any`, so a Link's
     * `parameters.schema` is no schema and its `parameters.paths` holds no path items. This cannot be said
     * by name alone, which is why a Link has a position of its own: `parameters` at an Operation IS a list
     * of Parameter Objects and `requestBody` there IS a Request Body Object, both carrying schemas 3.0 has
     * to convert.
     */
    private const array LINK_USER_DATA_FIELDS = ['parameters', 'requestBody'];

    /**
     * Fixed fields whose value is a map keyed by names the application chose — a status code, a media
     * type, a component name — and whose members are ordinary objects. `paths`, `callbacks` and `links`
     * are maps too and are listed separately, because what their members ARE is the thing the walk has to
     * know.
     */
    private const array NAMED_MAP_FIELDS = [
        'content',
        'encoding',
        'examples',
        'headers',
        'responses',
        'scopes',
        'variables',
    ];

    /** An OpenAPI object, read by its fixed field names. */
    private const string FIELDS = 'fields';

    /** A Path Item Object — fixed fields too, but `summary` and `description` are 3.0's own here. */
    private const string PATH_ITEM = 'path-item';

    /** A map keyed by application-chosen names, whose members are ordinary objects. */
    private const string NAMES = 'names';

    /** A map whose members are Path Items: `paths`, and a Callback Object's expression map. */
    private const string PATH_ITEMS = 'path-items';

    /** A map whose members are Callback Objects, each of them a map of Path Items. */
    private const string CALLBACKS = 'callbacks';

    /** A map whose members are Link Objects: a Response Object's `links`, and `components.links`. */
    private const string LINKS = 'links';

    /** A Link Object — an ordinary object but for {@see LINK_USER_DATA_FIELDS}. */
    private const string LINK = 'link';

    /** A Schema Object. */
    private const string SCHEMA = 'schema';

    /** A map whose members are Schema Objects. */
    private const string SCHEMA_MAP = 'schema-map';

    /** What a `$ref` to a shared path item starts with; 3.0 has nowhere for the member it names. */
    private const string SHARED_PATH_ITEM_REF = '#/components/pathItems/';

    /**
     * The 3.0 Path Item Object's operation members. `query` and `additionalOperations` are 3.2-only and
     * the 3.1 downlevel has already dropped them by the time this emitter runs.
     */
    private const array OPERATION_MEMBERS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * What the placeholder `default` response says. Addressed to whoever reads the API rather than to
     * whoever built it: it states what the document does not say and claims nothing about a status, a
     * media type or a body, since claiming any of those would be inventing a contract.
     */
    private const string UNDESCRIBED_RESPONSE = "This operation's responses are not described, so no status code or body is guaranteed.";

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
        [$array, $removed] = $this->downlevelComponents($array, $diagnostics);

        /** @var array<string, mixed> $walked */
        $walked = $this->walk($array, '#', self::FIELDS, $removed, $diagnostics);

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

        // Named, not counted: each one is a contract a consumer of this artifact no longer sees, and
        // the reader has to know which of them they are losing.
        $names = array_map(strval(...), array_keys(Arr::stringKeyed(is_array($array['webhooks']) ? $array['webhooks'] : [])));
        sort($names, SORT_STRING);

        unset($array['webhooks']);

        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'downlevel.webhooks',
            message: $names === []
                ? 'Dropped `webhooks` (#/webhooks), which OpenAPI 3.0 does not define.'
                : sprintf('Dropped `webhooks` (#/webhooks), which OpenAPI 3.0 does not define: %s.', implode(', ', $names)),
            help: 'Keep the 3.1 or 3.2 artifact for consumers that need the webhook contract.',
        );

        return $array;
    }

    /**
     * The two buckets 3.0 cannot keep, and what the walk owes each: a `$ref` naming a shared path item has
     * to be answered where it stands ({@see pathItemMap()}), and a requirement naming a dropped scheme has
     * to go with it.
     *
     * @param  array<string, mixed>  $array
     * @param  list<Diagnostic>  $diagnostics
     * @return array{array<string, mixed>, Removed}
     */
    private function downlevelComponents(array $array, array &$diagnostics): array
    {
        $removed = ['pathItems' => [], 'securitySchemes' => [], 'inlining' => []];

        if (! is_array($array['components'] ?? null)) {
            return [$array, $removed];
        }

        $components = Arr::stringKeyed($array['components']);

        if (isset($components['pathItems'])) {
            $shared = $components['pathItems'];
            $removed['pathItems'] = is_array($shared) ? Arr::stringKeyed($shared) : [];
            unset($components['pathItems']);

            // Info, not a warning: the bucket goes, and nothing a consumer reads goes with it. A path item
            // something references is inlined at each use site, and one nothing references describes no
            // path, so 3.0 loses the shared spelling rather than the contract.
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'downlevel.component-path-items',
                message: 'Dropped `components.pathItems` (#/components/pathItems), which OpenAPI 3.0 does not define; each path item a `$ref` names is inlined where it stands.',
            );
        }

        if (is_array($components['securitySchemes'] ?? null)) {
            [$schemes, $dropped] = $this->downlevelSecuritySchemes(Arr::stringKeyed($components['securitySchemes']), $diagnostics);
            $components['securitySchemes'] = $schemes;
            $removed['securitySchemes'] = $dropped;
        }

        $array['components'] = $components;

        return [$array, $removed];
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
     * Requirements naming a dropped scheme would dangle, so the name goes with it. An emptied requirement
     * is removed rather than left as `{}` — that would read as "no security required".
     *
     * @param  array<mixed, mixed>  $security
     * @param  list<string>  $dropped
     * @return list<mixed>
     */
    private static function withoutDroppedSchemes(array $security, array $dropped): array
    {
        $requirements = [];

        foreach ($security as $requirement) {
            $requirement = is_array($requirement) ? array_diff_key($requirement, array_flip($dropped)) : $requirement;

            if ($requirement !== []) {
                $requirements[] = $requirement;
            }
        }

        return $requirements;
    }

    /**
     * Descent over the whole document. `$kind` says what THIS node is and {@see member()} says what each
     * of its members is, so every decision below — a schema position, a path item, user data to hand back
     * untouched — follows from position alone.
     *
     * @param  Position  $kind
     * @param  Removed  $removed
     * @param  list<Diagnostic>  $diagnostics
     */
    private function walk(mixed $node, string $pointer, string $kind, array $removed, array &$diagnostics): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $list = array_is_list($node);

        if ($kind === self::PATH_ITEMS && ! $list) {
            return $this->pathItemMap(Arr::stringKeyed($node), $pointer, $removed, $diagnostics);
        }

        // A Path Item's `summary` and `description` are 3.0 fixed fields of its own, so a `$ref` stands
        // legally beside them there and nowhere else; everywhere else a `$ref` is a Reference Object.
        if (! $list && $kind === self::PATH_ITEM) {
            $node = $this->completeResponses(Arr::stringKeyed($node), $pointer, $diagnostics);
        } elseif (! $list && is_string($node['$ref'] ?? null)) {
            $node = $this->dropRefProse(Arr::stringKeyed($node), $pointer, $diagnostics);
        }

        $out = [];

        foreach ($node as $key => $value) {
            $key = (string) $key;
            $child = self::pointer($pointer, $key);

            // `security` is a fixed field of the document and of an Operation Object; a component may be
            // NAMED `security` without being one, so the parent's kind is what admits it. A Security
            // Requirement Object is `Map[scheme name, list<scope>]` — application-chosen keys over scope
            // strings, no document anywhere in it — so the walk stops here whatever a scheme is called.
            if ($kind === self::FIELDS && $key === 'security' && is_array($value)) {
                $requirements = $removed['securitySchemes'] === []
                    ? array_values($value)
                    : self::withoutDroppedSchemes($value, $removed['securitySchemes']);

                // Emptied by the scheme drop, not written empty: `security: []` is the document saying no
                // security is required, and it stays ({@see withoutDroppedSchemes()}).
                if ($requirements !== [] || $removed['securitySchemes'] === []) {
                    $out[$key] = $requirements;
                }

                continue;
            }

            $member = $list ? self::FIELDS : self::member($kind, $key, $pointer);

            $out[$key] = match ($member) {
                null => $value,
                self::SCHEMA => $this->subschema($value, $child, $diagnostics),
                self::SCHEMA_MAP => is_array($value) ? $this->schemaMap($value, $child, $diagnostics) : $value,
                default => $this->walk($value, $child, $member, $removed, $diagnostics),
            };
        }

        return $list ? array_values($out) : $out;
    }

    /**
     * What one member of a node is — the whole positional rule, in one place. Inside a map the application
     * named, every member is an object whatever its key is spelled; only at a fixed-field position does a
     * key name anything at all. A Link Object is an ordinary object at that position bar two fields whose
     * value is the application's ({@see LINK_USER_DATA_FIELDS}).
     *
     * @param  Position  $kind
     * @return Position|null null where the member is user data the walk must not descend into
     */
    private static function member(string $kind, string $key, string $pointer): ?string
    {
        if (str_starts_with($key, 'x-')) {
            return null;
        }

        return match ($kind) {
            self::NAMES => self::FIELDS,
            self::PATH_ITEMS => self::PATH_ITEM,
            self::CALLBACKS => self::PATH_ITEMS,
            self::LINKS => self::LINK,
            self::LINK => in_array($key, self::LINK_USER_DATA_FIELDS, true) ? null : self::field($key, $pointer),
            self::FIELDS, self::PATH_ITEM => self::field($key, $pointer),
            default => self::FIELDS,
        };
    }

    /**
     * One fixed field of an OpenAPI object. `components` is worth spelling out: its members are the
     * buckets, so those keys ARE fixed, and three of them hold something other than plain objects.
     * `pathItems` is not a fourth: the bucket is gone before the walk starts ({@see downlevelComponents()}).
     *
     * @return Position|null
     */
    private static function field(string $key, string $pointer): ?string
    {
        if ($pointer === '#/components') {
            return match ($key) {
                'schemas' => self::SCHEMA_MAP,
                'callbacks' => self::CALLBACKS,
                'links' => self::LINKS,
                default => self::NAMES,
            };
        }

        return match (true) {
            in_array($key, self::USER_DATA_FIELDS, true) => null,
            $key === 'schema' => self::SCHEMA,
            $key === 'paths' => self::PATH_ITEMS,
            $key === 'callbacks' => self::CALLBACKS,
            $key === 'links' => self::LINKS,
            in_array($key, self::NAMED_MAP_FIELDS, true) => self::NAMES,
            default => self::FIELDS,
        };
    }

    /**
     * A map of Path Items — `paths`, or the expression map of a Callback Object. 3.0 has no
     * `components.pathItems` for a `$ref` here to reach, so what one names is inlined where it stands,
     * which costs a 3.0 reader nothing but the shared spelling. A `$ref` that resolves to nothing has
     * nothing to inline, and publishing it would point a consumer at a member this emitter removed, so
     * the path goes with a warning naming both halves.
     *
     * @param  array<string, mixed>  $map
     * @param  Removed  $removed
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function pathItemMap(array $map, string $pointer, array $removed, array &$diagnostics): array
    {
        $out = [];

        foreach ($map as $key => $item) {
            $key = (string) $key;
            $child = self::pointer($pointer, $key);

            if (str_starts_with($key, 'x-') || ! is_array($item)) {
                $out[$key] = $item;

                continue;
            }

            $item = Arr::stringKeyed($item);
            $ref = self::sharedPathItem($item);

            if ($ref === null) {
                $out[$key] = $this->walk($item, $child, self::PATH_ITEM, $removed, $diagnostics);

                continue;
            }

            $resolved = self::inlinePathItem($item, $removed['pathItems'], $removed['inlining']);
            // The first hop is the `$ref` that got us here, so a chain is never really empty.
            $hops = $resolved['chain'] === [] ? [$ref] : $resolved['chain'];
            $chain = self::chain($hops);

            if ($resolved['item'] === null) {
                // Two causes, and one of them used to be reported as the other: a chain that closes on
                // itself never reaches a path item either, but the document DOES define what it names,
                // so telling the author to define it sends them to fix something that is already there.
                $diagnostics[] = $resolved['cycle']
                    ? new Diagnostic(
                        severity: Severity::Warning,
                        code: 'downlevel.path-item-unresolved',
                        message: sprintf(
                            'Dropped the path item at %s, whose `$ref` chain %s returns to `%s` and so reaches no path item: OpenAPI 3.0 has no `components.pathItems`, and there was nothing to inline in its place.',
                            $child,
                            $chain,
                            $hops[count($hops) - 1],
                        ),
                        help: 'Break the cycle: one of those shared path items has to describe the path rather than point at another, so 3.0 consumers keep the endpoint.',
                    )
                    : new Diagnostic(
                        severity: Severity::Warning,
                        code: 'downlevel.path-item-unresolved',
                        message: sprintf(
                            'Dropped the path item at %s, whose `$ref` chain %s ends at a shared path item this document does not define: OpenAPI 3.0 has no `components.pathItems`, and there was nothing to inline in its place.',
                            $child,
                            $chain,
                        ),
                        help: 'Define the shared path item, or write the path out where it is used, so 3.0 consumers keep the endpoint.',
                    );

                continue;
            }

            $item = $resolved['item'];

            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'downlevel.path-item-ref',
                message: sprintf(
                    'Inlined the shared path item %s at %s; OpenAPI 3.0 has no `components.pathItems` for a path item to reference.',
                    $chain,
                    $child,
                ),
            );

            // A path item cannot be inlined into itself, so every name on the chain stays OPEN while its
            // body is walked: a `$ref` back to one from a callback within is the cycle above rather than
            // a loop. Open, not removed — a sibling path referencing the same name still resolves, and a
            // name that is merely out of reach would otherwise read as a name nothing defines.
            $inner = $removed;
            $inner['inlining'] = [...$inner['inlining'], ...$hops];

            $out[$key] = $this->walk($item, $child, self::PATH_ITEM, $inner, $diagnostics);
        }

        return $out;
    }

    /**
     * The component name a path item's `$ref` reaches for, or null where it points anywhere else — an
     * external document, or a position 3.0 keeps a `$ref` to.
     *
     * @param  array<mixed, mixed>  $item
     */
    private static function sharedPathItem(array $item): ?string
    {
        $ref = $item['$ref'] ?? null;

        if (! is_string($ref) || ! str_starts_with($ref, self::SHARED_PATH_ITEM_REF)) {
            return null;
        }

        $token = substr($ref, strlen(self::SHARED_PATH_ITEM_REF));

        return $token === '' || str_contains($token, '/') ? null : str_replace(['~1', '~0'], ['/', '~'], $token);
    }

    /**
     * The path item a `$ref` chain resolves to, with the referencing item's own members kept over the
     * shared ones — which is how 3.1 reads a `summary` or `description` beside such a `$ref`.
     *
     * `chain` is every hop it took, ending at the one that failed, so a caller can name where a chain
     * stopped rather than where it started. Two ways it stops: a hop names nothing this document
     * defines, or it names one already open — a name on $inlining, which the caller keeps while it
     * walks that name's body, or one this chain has followed itself.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $shared
     * @param  list<string>  $inlining
     * @return PathItemChain
     */
    private static function inlinePathItem(array $item, array $shared, array $inlining): array
    {
        $names = [];

        for ($name = self::sharedPathItem($item); $name !== null; $name = self::sharedPathItem($item)) {
            if (in_array($name, $names, true) || in_array($name, $inlining, true)) {
                return ['item' => null, 'chain' => [...$names, $name], 'cycle' => true];
            }

            $target = $shared[$name] ?? null;

            if (! is_array($target)) {
                return ['item' => null, 'chain' => [...$names, $name], 'cycle' => false];
            }

            $names[] = $name;
            unset($item['$ref']);
            $item += Arr::stringKeyed($target);
        }

        return ['item' => $item, 'chain' => $names, 'cycle' => false];
    }

    /**
     * A `$ref` chain as a reader sees it: one name, or every hop it took, in the order taken.
     *
     * @param  non-empty-list<string>  $names
     */
    private static function chain(array $names): string
    {
        return '`'.implode('` → `', $names).'`';
    }

    /**
     * A Reference Object states `summary` and `description` of its own from 3.1 on, and the shared-error
     * hoist uses them to keep one operation's wording out of every other's.
     * 3.0 ignores anything beside a `$ref`, so what it would ignore comes
     * off rather than sitting in the document reading as though it applied — a schema's siblings have an
     * `allOf` to move into ({@see hoistRefSiblings()}); prose about a response has nowhere, and the words
     * the component itself publishes stand in its place.
     *
     * @param  array<string, mixed>  $node
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function dropRefProse(array $node, string $pointer, array &$diagnostics): array
    {
        $dropped = array_keys(array_intersect_key($node, array_flip(['summary', 'description'])));
        if ($dropped === []) {
            return $node;
        }

        foreach ($dropped as $member) {
            unset($node[$member]);
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Info,
            code: 'downlevel.ref-siblings',
            message: sprintf(
                'Dropped `%s` from the reference at %s; OpenAPI 3.0 ignores a `$ref` sibling, so the referenced component\'s own wording is what a 3.0 reader sees.',
                implode('` and `', $dropped),
                $pointer,
            ),
        );

        return $node;
    }

    /**
     * A path item's operations, each carrying the `responses` 3.0 requires. 3.1 and 3.2 accept an
     * operation with none — a deferred handler, a handler that threw while it was being read — but 3.0
     * makes the member REQUIRED and its map `minProperties: 1`, so what would otherwise be an invalid
     * Operation Object gains a `default` response with a description and nothing else: an honest degraded
     * answer rather than an invented status, media type or schema.
     *
     * Repeated inline rather than componentized. There is no body to share, so a `$ref` would cost the
     * reader a hop and every generated client a type name, both for one sentence saying nothing.
     *
     * @param  array<string, mixed>  $item
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function completeResponses(array $item, string $pointer, array &$diagnostics): array
    {
        foreach (self::OPERATION_MEMBERS as $method) {
            $operation = $item[$method] ?? null;

            if (! is_array($operation) || (array_key_exists('responses', $operation) && $operation['responses'] !== [])) {
                continue;
            }

            $operation['responses'] = ['default' => ['description' => self::UNDESCRIBED_RESPONSE]];
            $item[$method] = $operation;

            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'downlevel.empty-responses',
                message: sprintf(
                    'Added a placeholder `default` response to the operation at %s, which documents none; OpenAPI 3.0 requires every operation to declare at least one.',
                    self::pointer($pointer, $method),
                ),
                routeSignature: self::routeSignature($pointer, $method),
                help: 'Name what the endpoint returns — a #[Response] attribute, a return docblock, or an overlay — so the artifact carries the real shape rather than a placeholder.',
            );
        }

        return $item;
    }

    /**
     * `GET /api/ping` for a path item under `paths`, so a note lands beside that route's other
     * diagnostics; null for a callback's path item, whose key is a runtime expression rather than a route.
     * The message names the JSON pointer either way.
     */
    private static function routeSignature(string $pointer, string $method): ?string
    {
        $tokens = explode('/', $pointer);

        return count($tokens) === 3 && $tokens[1] === 'paths'
            ? strtoupper($method).' '.str_replace(['~1', '~0'], ['/', '~'], $tokens[2])
            : null;
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
            $out[$name] = $this->subschema($schema, self::pointer($pointer, $name), $diagnostics);
        }

        return $out;
    }

    /**
     * ONE subschema, wherever it sits. A boolean is a schema in 2020-12 and 3.0's draft-4-shaped Schema
     * Object is an object at every position but {@see BOOLEAN_SCHEMA_KEYWORD} — so it is rewritten into
     * the 3.0 spelling of the SAME constraint rather than passed through invalid or dropped: `true` is
     * the empty schema, and `false` is the schema nothing satisfies, which 3.0 writes as `{not: {}}`.
     * Both are exact, which is why dropping the keyword — the other answer available — is the worse one:
     * `items: false` says the array must be empty, and a document that stops saying so is not vaguer,
     * it is wrong.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    private function subschema(mixed $value, string $pointer, array &$diagnostics): mixed
    {
        if (is_bool($value)) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'downlevel.boolean-subschema',
                message: sprintf(
                    'Rewrote the boolean schema `%s` at %s as `%s`, which is how OpenAPI 3.0 says the same thing.',
                    $value ? 'true' : 'false',
                    $pointer,
                    $value ? '{}' : '{"not": {}}',
                ),
            );

            return $value ? [] : ['not' => []];
        }

        return is_array($value) ? $this->schema(Arr::stringKeyed($value), $pointer, $diagnostics) : $value;
    }

    /**
     * @param  array<mixed, mixed>  $list
     * @param  list<Diagnostic>  $diagnostics
     * @return list<mixed>
     */
    private function subschemaList(array $list, string $pointer, array &$diagnostics): array
    {
        $out = [];

        foreach (array_values($list) as $index => $branch) {
            $out[] = $this->subschema($branch, $pointer.'/'.$index, $diagnostics);
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
        foreach ($schema as $key => $value) {
            $keyword = (string) $key;

            // The one boolean 3.0 takes as written, so nothing to convert and nothing to report.
            if ($keyword === self::BOOLEAN_SCHEMA_KEYWORD && is_bool($value)) {
                continue;
            }

            $child = $pointer.'/'.$keyword;

            $schema[$keyword] = match (SchemaKeywords::positionOf($keyword)) {
                SchemaKeywords::POSITION_SCHEMA => $this->subschema($value, $child, $diagnostics),
                SchemaKeywords::POSITION_SCHEMA_MAP => is_array($value) ? $this->schemaMap($value, $child, $diagnostics) : $value,
                SchemaKeywords::POSITION_SCHEMA_LIST => is_array($value) ? $this->subschemaList($value, $child, $diagnostics) : $value,
                default => $value,
            };
        }

        return $schema;
    }
}
