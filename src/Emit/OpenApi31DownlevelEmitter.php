<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\JsonPointer;
use stdClass;

/**
 * Downlevels pure OpenAPI 3.2 to 3.1 for toolchains that lag the 3.2 release. Lossy transforms warn
 * into an {@see EmitReport} rather than silently dropping things:
 *
 * - `openapi` becomes `3.1.1`;
 * - the 3.2 `jsonSchemaDialect` base is rewritten to the 3.1 base;
 * - the 3.2-only `query` HTTP method is dropped (warning);
 * - the 3.2-only `additionalOperations` path-item member is dropped (warning);
 * - the 3.2-only tag members `summary`, `parent` and `kind` are dropped (a warning each);
 * - every other member 3.2 added is dropped where it stands ({@see MEMBERS_32}, one shared warning);
 * - every VALUE 3.2 added to a member's domain is answered where it stands ({@see VALUES_32}, one
 *   shared warning).
 *
 * The rest of 3.2 is compatible with 3.1's JSON Schema dialect and passes through unchanged.
 *
 * A member is 3.2-only by POSITION, never by name: `description` is 3.2-only on a Media Type and
 * original on a Response, `summary` is 3.2-only on a Response and original on an Operation, `name` is
 * 3.2-only on a Server and original on a Parameter, and `encoding` is 3.2-only on an Encoding Object
 * and original on the Media Type holding it. So the drop runs off a descent that knows what each node
 * IS ({@see CHILDREN}), and dropping by name alone would strip original members from four objects.
 *
 * A member 3.2 ADDED and a domain 3.2 WIDENED are two axes of one loss, and a member-shaped table sees
 * only the first: `in` exists in every version and it is the value `querystring` that does not, so a
 * parameter carrying it emitted verbatim into documents whose own meta-schemas reject it.
 * {@see VALUES_32} is the second axis, derived from the same two vendored meta-schemas the member table
 * is checked against (`OpenApiValueDelta`), so a value the next version widens is found without a report.
 *
 * @phpstan-type ValueLoss array{member: string, value: string, through: string|null}
 * @phpstan-type PrePass array{mediaTypes: array<string, mixed>, parameters: array<string, ValueLoss>}
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

    private const string COMPONENTS = 'components';

    private const string PATH_ITEM = 'path-item';

    private const string OPERATION = 'operation';

    private const string REQUEST_BODY = 'request-body';

    private const string RESPONSE = 'response';

    private const string PARAMETER = 'parameter';

    private const string HEADER = 'header';

    private const string MEDIA_TYPE = 'media-type';

    private const string ENCODING = 'encoding';

    private const string EXAMPLE = 'example';

    private const string LINK = 'link';

    private const string SERVER = 'server';

    private const string SECURITY_SCHEME = 'security-scheme';

    private const string OAUTH_FLOWS = 'oauth-flows';

    /** One child object. */
    private const string ONE = 'one';

    /** A list of child objects. */
    private const string LIST = 'list';

    /** A map, keyed by names the application chose, of child objects. */
    private const string MAP = 'map';

    /** A map of Path Items, which {@see downlevelPathMap()} owns. */
    private const string PATH_ITEMS = 'path-items';

    /** A map of Callback Objects, each of them a map of Path Items. */
    private const string CALLBACKS = 'callbacks';

    /**
     * Every member OpenAPI 3.2 added that 3.1 has no word for, by the object that carries it. The Tag
     * Object and the Path Item are absent on purpose: their losses have remedies of their own to state,
     * so they keep a code each ({@see TAG_MEMBERS_32}, {@see downlevelPathItem()}). Every member here
     * has the SAME remedy — keep the 3.2 artifact — which is why one code covers them all.
     *
     * @var array<string, list<string>>
     */
    private const array MEMBERS_32 = [
        self::COMPONENTS => ['mediaTypes'],
        self::RESPONSE => ['summary'],
        self::MEDIA_TYPE => ['description', 'itemSchema', 'prefixEncoding', 'itemEncoding'],
        self::ENCODING => ['encoding', 'prefixEncoding', 'itemEncoding'],
        self::EXAMPLE => ['dataValue', 'serializedValue'],
        self::SERVER => ['name'],
        self::SECURITY_SCHEME => ['deprecated', 'oauth2MetadataUrl'],
        self::OAUTH_FLOWS => ['deviceAuthorization'],
    ];

    /** A 3.2-only value the object cannot be expressed without: the whole object goes. */
    private const string DROP_NODE = 'node';

    /** A 3.2-only value in an optional member: the member goes and the object stands. */
    private const string DROP_MEMBER = 'member';

    /**
     * Every VALUE OpenAPI 3.2 added to a member's domain, by the object and member carrying it, with what
     * the loss costs and the help that states it.
     *
     * `DROP_NODE` where the value is the object's whole reason to exist: an `in: querystring` parameter
     * carries the raw query string as ONE value and takes `content` rather than `schema`, so 3.1 has no
     * other spelling for it and the parameter itself goes. `DROP_MEMBER` where the member is optional and
     * the older version's own default is the nearest true thing: 3.1 spells only `form` for a cookie
     * parameter's `style`, which is what every 3.1 cookie parameter already says.
     *
     * `DROP_MEMBER` writes NOTHING in the dropped member's place, and `explode` is the near miss that says
     * why. 3.2 defaults it to true for `style: cookie` exactly as 3.1 does for the `form` a cookie
     * parameter falls back to, so the drop moves it nowhere; and no `explode` would reproduce `cookie`
     * anyway, since `form` percent-encodes where `cookie` escapes nothing and joins an exploded value on
     * `&` rather than `; `. Pinning one would trade a lost spelling for a false one. Read that default off
     * the PROSE — the vendored meta-schemas annotate `explode` false for any style not literally `form`,
     * contradicting 3.2's own text, and a JSON Schema `default` is an annotation nothing applies.
     *
     * @var array<string, array<string, array<string, array{string, string}>>>
     */
    private const array VALUES_32 = [
        self::PARAMETER => [
            'in' => [
                'querystring' => [
                    self::DROP_NODE,
                    'Keep the 3.2 artifact for consumers that need this input: OpenAPI 3.1 has no way to describe the raw query string as one value, and a `query` parameter is a different contract rather than the same one renamed.',
                ],
            ],
            'style' => [
                'cookie' => [
                    self::DROP_MEMBER,
                    'The parameter stands at `form`, the only cookie style OpenAPI 3.1 spells and its default there, and nothing is written in the dropped member\'s place: both styles default `explode` to true, so an exploded value stays exploded. What the older artifacts cannot say is the rest of RFC 6265 — `form` percent-encodes where `cookie` escapes nothing, and joins an exploded array or object on `&` rather than `; `. Keep the 3.2 artifact for consumers that need the cookie wire format.',
                ],
            ],
        ],
    ];

    /**
     * What each member of each object IS, so the descent reaches every position {@see MEMBERS_32} names.
     * A member dropped by that table needs no line here — `mediaTypes` and the two 3.2-only encoding
     * slots are gone before the descent would reach them.
     *
     * @var array<string, array<string, array{string, string}>>
     */
    private const array CHILDREN = [
        self::COMPONENTS => [
            'responses' => [self::MAP, self::RESPONSE],
            'parameters' => [self::MAP, self::PARAMETER],
            'examples' => [self::MAP, self::EXAMPLE],
            'requestBodies' => [self::MAP, self::REQUEST_BODY],
            'headers' => [self::MAP, self::HEADER],
            'securitySchemes' => [self::MAP, self::SECURITY_SCHEME],
            'links' => [self::MAP, self::LINK],
            'callbacks' => [self::CALLBACKS, self::PATH_ITEM],
            'pathItems' => [self::PATH_ITEMS, self::PATH_ITEM],
        ],
        self::OPERATION => [
            'servers' => [self::LIST, self::SERVER],
            'parameters' => [self::LIST, self::PARAMETER],
            'requestBody' => [self::ONE, self::REQUEST_BODY],
            'responses' => [self::MAP, self::RESPONSE],
            'callbacks' => [self::CALLBACKS, self::PATH_ITEM],
        ],
        self::REQUEST_BODY => [
            'content' => [self::MAP, self::MEDIA_TYPE],
        ],
        self::RESPONSE => [
            'headers' => [self::MAP, self::HEADER],
            'content' => [self::MAP, self::MEDIA_TYPE],
            'links' => [self::MAP, self::LINK],
        ],
        self::PARAMETER => [
            'content' => [self::MAP, self::MEDIA_TYPE],
            'examples' => [self::MAP, self::EXAMPLE],
        ],
        self::HEADER => [
            'content' => [self::MAP, self::MEDIA_TYPE],
            'examples' => [self::MAP, self::EXAMPLE],
        ],
        self::MEDIA_TYPE => [
            'encoding' => [self::MAP, self::ENCODING],
            'examples' => [self::MAP, self::EXAMPLE],
        ],
        self::ENCODING => [
            'headers' => [self::MAP, self::HEADER],
        ],
        self::LINK => [
            'server' => [self::ONE, self::SERVER],
        ],
        self::SECURITY_SCHEME => [
            'flows' => [self::ONE, self::OAUTH_FLOWS],
        ],
    ];

    /** Where a `$ref` into the 3.2-only shared media types points; 3.1 has no bucket for it to reach. */
    private const string SHARED_MEDIA_TYPE_REF = '#/components/mediaTypes/';

    /** Where a `$ref` into the shared parameters bucket points. */
    private const string SHARED_PARAMETER_REF = '#/components/parameters/';

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

        // Two things are read off the document before anything walks it, because a `$ref` has to be
        // answered at its USE site: the shared media types get inlined where one names them, and a shared
        // parameter 3.1 cannot express goes, taking every `$ref` that named it.
        $read = [
            'mediaTypes' => $this->sharedMediaTypes($array),
            'parameters' => $this->inexpressibleParameters($array),
        ];

        if (isset($array['tags']) && is_array($array['tags'])) {
            $array['tags'] = $this->downlevelTags($array['tags'], $diagnostics, isset($array['x-tagGroups']));
        }

        if (isset($array['servers']) && is_array($array['servers'])) {
            $array['servers'] = $this->walkList(self::SERVER, $array['servers'], '#/servers', $read, $diagnostics);
        }

        foreach (['paths', 'webhooks'] as $bucket) {
            if (isset($array[$bucket]) && is_array($array[$bucket])) {
                $array[$bucket] = $this->downlevelPathMap($array[$bucket], JsonPointer::child('#', $bucket), $read, $diagnostics);
            }
        }

        if (isset($array['components']) && is_array($array['components'])) {
            $array['components'] = $this->walkObject(self::COMPONENTS, Arr::stringKeyed($array['components']), '#/components', $read, $diagnostics);
        }

        return $array;
    }

    /**
     * The 3.2-only `components.mediaTypes` bucket, read before it is dropped. A `$ref` naming one is
     * inlined where it stands ({@see walkMediaTypeMap()}), which costs a 3.1 reader nothing but the
     * shared spelling and is the only way dropping the bucket does not dangle every `$ref` into it.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function sharedMediaTypes(array $array): array
    {
        $components = is_array($array['components'] ?? null) ? Arr::stringKeyed($array['components']) : [];
        $bucket = $components['mediaTypes'] ?? null;

        return is_array($bucket) ? Arr::stringKeyed($bucket) : [];
    }

    /**
     * The `components.parameters` entries no 3.1 document can carry, by name, with the loss that decided
     * it. A `$ref` chain inside the bucket is followed to its target, so a name reaching an inexpressible
     * parameter through another name goes too: the alternative is a `$ref` left pointing at a component
     * the 3.1 artifact no longer has.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, ValueLoss>
     */
    private function inexpressibleParameters(array $array): array
    {
        $components = is_array($array['components'] ?? null) ? Arr::stringKeyed($array['components']) : [];
        $bucket = is_array($components['parameters'] ?? null) ? Arr::stringKeyed($components['parameters']) : [];

        $found = [];

        foreach (array_keys($bucket) as $name) {
            $loss = $this->parameterChainLoss((string) $name, $bucket);

            if ($loss !== null) {
                $found[(string) $name] = $loss;
            }
        }

        return $found;
    }

    /**
     * The loss that makes one shared parameter inexpressible, following the `$ref` chain from it and
     * stopping at a name already seen, so a cycle ends rather than resolving.
     *
     * @param  array<string, mixed>  $bucket
     * @param  list<string>  $seen
     * @return ValueLoss|null
     */
    private function parameterChainLoss(string $name, array $bucket, array $seen = []): ?array
    {
        if (in_array($name, $seen, true) || ! is_array($bucket[$name] ?? null)) {
            return null;
        }

        $parameter = Arr::stringKeyed($bucket[$name]);
        $loss = $this->inexpressibleValue(self::PARAMETER, $parameter, self::DROP_NODE);

        if ($loss !== null) {
            return $loss;
        }

        $ref = $parameter['$ref'] ?? null;

        if (! is_string($ref) || ! str_starts_with($ref, self::SHARED_PARAMETER_REF)) {
            return null;
        }

        $target = substr($ref, strlen(self::SHARED_PARAMETER_REF));
        $loss = $this->parameterChainLoss($target, $bucket, [...$seen, $name]);

        return $loss === null ? null : ['member' => $loss['member'], 'value' => $loss['value'], 'through' => $ref];
    }

    /**
     * The first member of one object stating a value 3.2 added and $how says the object cannot survive,
     * or null when it states none.
     *
     * @param  array<string, mixed>  $node
     * @return ValueLoss|null
     */
    private function inexpressibleValue(string $kind, array $node, string $how): ?array
    {
        foreach (self::VALUES_32[$kind] ?? [] as $member => $values) {
            $stated = $node[$member] ?? null;

            if (is_string($stated) && ($values[$stated][0] ?? null) === $how) {
                return ['member' => $member, 'value' => $stated, 'through' => null];
            }
        }

        return null;
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
     * @param  PrePass  $read
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelPathMap(array $paths, string $pointer, array $read, array &$diagnostics): array
    {
        $out = [];

        foreach ($paths as $template => $item) {
            $template = (string) $template;

            if (is_array($item)) {
                $out[$template] = $this->downlevelPathItem($template, $item, JsonPointer::child($pointer, $template), $read, $diagnostics);

                continue;
            }

            $out[$template] = $item;
        }

        return $out;
    }

    /**
     * @param  array<mixed, mixed>  $item
     * @param  PrePass  $read
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function downlevelPathItem(string $template, array $item, string $pointer, array $read, array &$diagnostics): array
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

        $item = Arr::stringKeyed($item);

        if (isset($item['servers']) && is_array($item['servers'])) {
            $item['servers'] = $this->walkList(self::SERVER, $item['servers'], JsonPointer::child($pointer, 'servers'), $read, $diagnostics);
        }

        if (isset($item['parameters']) && is_array($item['parameters'])) {
            $arrived = $item['parameters'];
            $item['parameters'] = $this->walkList(self::PARAMETER, $arrived, JsonPointer::child($pointer, 'parameters'), $read, $diagnostics);

            // Emptied by the drop, so gone — the invariant {@see walkObject()} states for every other
            // parameter position.
            if ($item['parameters'] === [] && $arrived !== []) {
                unset($item['parameters']);
            }
        }

        foreach (PathItem::METHODS as $method) {
            if (isset($item[$method]) && is_array($item[$method])) {
                $item[$method] = $this->walkObject(self::OPERATION, Arr::stringKeyed($item[$method]), JsonPointer::child($pointer, $method), $read, $diagnostics);
            }
        }

        return $item;
    }

    /**
     * One object: its 3.2-only members dropped, then each member the position knows about descended
     * into. A `$ref` node is left alone bar its own 3.2-only members — there is nothing else in it.
     *
     * An object THIS PASS empties — by a member drop, or by the parameter member a node drop emptied —
     * comes back as {@see stdClass}, so it is still published as `{}`. The
     * canonicaliser answers that from position for every object it knows ({@see Canonicalizer}), but an
     * Encoding Object and an OAuth Flows Object are read generically there, and `[]` at either is a
     * document no validator accepts — the loss must not turn an object into a list on the way out. Only
     * an object the drop emptied converts: one that ARRIVED empty stays an array, because the 3.0 pass
     * reads this same intermediate and has its own answer for an operation written `{}`.
     *
     * @param  array<string, mixed>  $node
     * @param  PrePass  $read
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|stdClass
     */
    private function walkObject(string $kind, array $node, string $pointer, array $read, array &$diagnostics): array|stdClass
    {
        $arrived = $node;
        $node = $this->dropMembers32($kind, $node, $pointer, $diagnostics);
        $node = $this->dropValues32($kind, $node, $pointer, $diagnostics);

        foreach (self::CHILDREN[$kind] ?? [] as $member => [$shape, $childKind]) {
            if (! isset($node[$member]) || ! is_array($node[$member])) {
                continue;
            }

            $child = JsonPointer::child($pointer, $member);
            $value = $node[$member];

            $node[$member] = match ($shape) {
                self::ONE => $this->walkObject($childKind, Arr::stringKeyed($value), $child, $read, $diagnostics),
                self::LIST => $this->walkList($childKind, $value, $child, $read, $diagnostics),
                self::PATH_ITEMS => $this->downlevelPathMap($value, $child, $read, $diagnostics),
                self::CALLBACKS => $this->walkMap($childKind, $value, $child, $read, $diagnostics, callbacks: true),
                default => $this->walkMap($childKind, $value, $child, $read, $diagnostics),
            };

            // A parameter member the drop emptied goes with it. `parameters: []` and an empty component
            // bucket are both legal and neither is a shape any document the product writes carries, so
            // leaving one would publish evidence of the loss instead of the loss itself.
            if ($childKind === self::PARAMETER && $node[$member] === [] && $value !== []) {
                unset($node[$member]);
            }
        }

        return $node === [] && $arrived !== [] ? new stdClass : $node;
    }

    /**
     * Every 3.2-only member of one object, gone with one warning each. The pointer is the whole locator:
     * these members sit inside content and encoding maps, where nothing else would say which one.
     *
     * @param  array<string, mixed>  $node
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function dropMembers32(string $kind, array $node, string $pointer, array &$diagnostics): array
    {
        foreach (self::MEMBERS_32[$kind] ?? [] as $member) {
            if (! array_key_exists($member, $node)) {
                continue;
            }

            unset($node[$member]);
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.member-not-in-3.1',
                message: sprintf(
                    'Dropped the OpenAPI 3.2 `%s` member (%s), which OpenAPI 3.1 and below do not define.',
                    $member,
                    JsonPointer::child($pointer, $member),
                ),
                help: 'Keep the 3.2 artifact for full fidelity; 3.1 and 3.0 consumers cannot be told this.',
            );
        }

        return $node;
    }

    /**
     * The members of one object whose 3.2-only VALUE costs the member and no more, gone with a warning
     * each. The object itself stands: {@see VALUES_32} says which of the two answers each value takes,
     * and the ones that take the object are answered by whatever HOLDS it ({@see walkList()},
     * {@see walkMap()}), since nothing can remove itself from its own container.
     *
     * @param  array<string, mixed>  $node
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function dropValues32(string $kind, array $node, string $pointer, array &$diagnostics): array
    {
        foreach (self::VALUES_32[$kind] ?? [] as $member => $values) {
            $stated = $node[$member] ?? null;

            if (! is_string($stated) || ($values[$stated][0] ?? null) !== self::DROP_MEMBER) {
                continue;
            }

            unset($node[$member]);
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'downlevel.value-not-in-3.1',
                message: sprintf(
                    'Dropped the OpenAPI 3.2 `%s: %s` value (%s), which OpenAPI 3.1 and below do not define.',
                    $member,
                    $stated,
                    JsonPointer::child($pointer, $member),
                ),
                help: $values[$stated][1],
            );
        }

        return $node;
    }

    /**
     * @param  array<mixed, mixed>  $list
     * @param  PrePass  $read
     * @param  list<Diagnostic>  $diagnostics
     * @return list<mixed>
     */
    private function walkList(string $kind, array $list, string $pointer, array $read, array &$diagnostics): array
    {
        $out = [];

        foreach (array_values($list) as $index => $item) {
            if (! is_array($item)) {
                $out[] = $item;

                continue;
            }

            $item = Arr::stringKeyed($item);
            $child = JsonPointer::child($pointer, (string) $index);

            if ($kind === self::PARAMETER && $this->parameterIsDropped($item, $read, $child, $diagnostics)) {
                continue;
            }

            $out[] = $this->walkObject($kind, $item, $child, $read, $diagnostics);
        }

        return $out;
    }

    /**
     * Whether one member of a parameter list has to go: it states a value 3.1 has no word for, or it is a
     * `$ref` naming a shared parameter that does. Only the first warns — a shared parameter is reported
     * once where it is DEFINED ({@see walkMap()}), the way a dropped security scheme is, rather than again
     * at every use site.
     *
     * @param  array<string, mixed>  $parameter
     * @param  PrePass  $read
     * @param  list<Diagnostic>  $diagnostics
     */
    private function parameterIsDropped(array $parameter, array $read, string $pointer, array &$diagnostics): bool
    {
        $ref = $parameter['$ref'] ?? null;

        if (is_string($ref) && str_starts_with($ref, self::SHARED_PARAMETER_REF)) {
            return isset($read['parameters'][substr($ref, strlen(self::SHARED_PARAMETER_REF))]);
        }

        $loss = $this->inexpressibleValue(self::PARAMETER, $parameter, self::DROP_NODE);

        if ($loss === null) {
            return false;
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'downlevel.value-not-in-3.1',
            message: sprintf(
                'Dropped the parameter `%s` (%s), whose OpenAPI 3.2 `%s: %s` value OpenAPI 3.1 and below do not define.',
                is_string($parameter['name'] ?? null) ? $parameter['name'] : '(unnamed)',
                $pointer,
                $loss['member'],
                $loss['value'],
            ),
            help: self::VALUES_32[self::PARAMETER][$loss['member']][$loss['value']][1],
        );

        return true;
    }

    /**
     * A map keyed by names the application chose. `$callbacks` says its members are themselves maps of
     * Path Items rather than objects of `$kind`. The one map a member can leave entirely is
     * `components.parameters`: a shared parameter 3.1 cannot express is reported here, where it is
     * defined, and {@see parameterIsDropped()} takes the `$ref`s that named it.
     *
     * @param  array<mixed, mixed>  $map
     * @param  PrePass  $read
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function walkMap(string $kind, array $map, string $pointer, array $read, array &$diagnostics, bool $callbacks = false): array
    {
        if ($kind === self::MEDIA_TYPE) {
            return $this->walkMediaTypeMap($map, $pointer, $read, $diagnostics);
        }

        $out = [];

        foreach ($map as $name => $member) {
            $name = (string) $name;
            $child = JsonPointer::child($pointer, $name);

            if (str_starts_with($name, 'x-') || ! is_array($member)) {
                $out[$name] = $member;

                continue;
            }

            if ($kind === self::PARAMETER && isset($read['parameters'][$name])) {
                $this->reportDroppedParameter($name, $read['parameters'][$name], $child, $diagnostics);

                continue;
            }

            $out[$name] = $callbacks
                ? $this->downlevelPathMap($member, $child, $read, $diagnostics)
                : $this->walkObject($kind, Arr::stringKeyed($member), $child, $read, $diagnostics);
        }

        return $out;
    }

    /**
     * One shared parameter leaving the document, said once. `through` is the `$ref` it resolved along when
     * the parameter itself states nothing 3.1 objects to and the component it points at does.
     *
     * @param  ValueLoss  $loss
     * @param  list<Diagnostic>  $diagnostics
     */
    private function reportDroppedParameter(string $name, array $loss, string $pointer, array &$diagnostics): void
    {
        $through = $loss['through'];

        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'downlevel.value-not-in-3.1',
            message: $through === null
                ? sprintf(
                    'Dropped the shared parameter `%s` (%s), whose OpenAPI 3.2 `%s: %s` value OpenAPI 3.1 and below do not define, along with every `$ref` naming it.',
                    $name,
                    $pointer,
                    $loss['member'],
                    $loss['value'],
                )
                : sprintf(
                    'Dropped the shared parameter `%s` (%s), which resolves through `%s` to an OpenAPI 3.2 `%s: %s` value OpenAPI 3.1 and below do not define, along with every `$ref` naming it.',
                    $name,
                    $pointer,
                    $through,
                    $loss['member'],
                    $loss['value'],
                ),
            help: self::VALUES_32[self::PARAMETER][$loss['member']][$loss['value']][1],
        );
    }

    /**
     * A `content` map, or the 3.2-only shared bucket read like one. A member that is a `$ref` into
     * `components.mediaTypes` becomes the media type it names, since 3.1 keeps no bucket for it to
     * reach; one that resolves to nothing is left as written rather than replaced with a guess.
     *
     * @param  array<mixed, mixed>  $map
     * @param  PrePass  $read
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function walkMediaTypeMap(array $map, string $pointer, array $read, array &$diagnostics): array
    {
        $out = [];

        foreach ($map as $name => $member) {
            $name = (string) $name;
            $child = JsonPointer::child($pointer, $name);

            if (str_starts_with($name, 'x-') || ! is_array($member)) {
                $out[$name] = $member;

                continue;
            }

            $member = Arr::stringKeyed($member);
            $ref = $member['$ref'] ?? null;

            if (is_string($ref) && str_starts_with($ref, self::SHARED_MEDIA_TYPE_REF)) {
                $target = $read['mediaTypes'][substr($ref, strlen(self::SHARED_MEDIA_TYPE_REF))] ?? null;

                if (is_array($target)) {
                    $member = Arr::stringKeyed($target);
                }
            }

            $out[$name] = $this->walkObject(self::MEDIA_TYPE, $member, $child, $read, $diagnostics);
        }

        return $out;
    }
}
