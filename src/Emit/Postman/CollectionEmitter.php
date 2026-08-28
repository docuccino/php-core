<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\Refs;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\EmitReport;
use Docuccino\Core\Emit\EmitResult;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ReportingEmitter;
use Docuccino\Core\Emit\SchemaExampleFactory;
use Docuccino\Core\Support\Arr;

/**
 * Emits a {@see UirDocument} as a Postman Collection v2.1.0: folders from the document's tags,
 * one request per operation, and saved examples from the documented responses.
 *
 * The entry point of this namespace. Everything beside it here is a collaborator it owns — {@see Auth},
 * {@see Body}, {@see Description}, {@see Folders}, {@see SavedExample}, {@see Url} — each holding one
 * mapping table so that table has a single owner and a single dataset test. Only this class is reached
 * from outside, through {@see Formats}.
 *
 * **Ordering comes from construction, not from {@see Canonicalizer}.** That
 * canonicaliser is an OAS-document-shaped handler table; run a collection through it and it reorders
 * `info`/`item`/`variable` and re-keys nested nodes into something Postman cannot read. Every builder
 * here writes its members in a fixed literal order and the result goes straight to the serializer.
 *
 * **The collection carries no identifier, timestamp or path that is not a function of what the
 * document publishes.** So there is no `info._postman_id` and no item `id` — the v2.1.0 schema
 * requires neither, Postman mints its own uid on import, and any value we invented would be either
 * random (which determinism forbids) or a hash we would then owe forever. No `responseTime` either:
 * a `null` there would claim we measured and got nothing.
 *
 * The 3.2-only `query` method needs no downlevelling — Postman's `request.method` is a free string,
 * so it survives intact. Do not copy the sibling downlevel emitters' warning for it.
 *
 * @internal
 */
final readonly class CollectionEmitter implements ReportingEmitter
{
    private const string SCHEMA_URL = 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json';

    /** Enough for 200/201/400/401/403/422; past that a collection stops being navigable. */
    private const int MAX_EXAMPLES = 6;

    /** Which media type a request body is built from, most representable first. */
    private const array MEDIA_PREFERENCE = ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'];

    public function __construct(
        private OpenApi32Emitter $oas32 = new OpenApi32Emitter,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
        private SchemaExampleFactory $examples = new SchemaExampleFactory,
        private Auth $auth = new Auth,
        private Url $urls = new Url,
    ) {}

    public function format(): string
    {
        return 'postman';
    }

    public function emit(UirDocument $document, EmitOptions $options = new EmitOptions): string
    {
        return $this->emitWithReport($document, $options)->output;
    }

    public function emitWithReport(UirDocument $document, EmitOptions $options = new EmitOptions): EmitResult
    {
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        $collection = $this->toCollectionArray($document, $diagnostics, $options);

        return new EmitResult($this->serializer->serialize($collection), new EmitReport($diagnostics));
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    public function toCollectionArray(UirDocument $document, array &$diagnostics, EmitOptions $options = new EmitOptions): array
    {
        // Every fabricated value in here comes from one of two collaborators, so the document's
        // configured format samples are bound once, before anything reads a schema.
        $bound = $this->withFormatSamples($options->formatSamples);

        return $bound->collection($document, $diagnostics, $options);
    }

    /**
     * @param  array<string, string>  $samples
     */
    private function withFormatSamples(array $samples): self
    {
        $examples = $this->examples->withFormatSamples($samples);
        $urls = $this->urls->withFormatSamples($samples);

        return $examples === $this->examples && $urls === $this->urls
            ? $this
            : new self($this->oas32, $this->serializer, $examples, $this->auth, $urls);
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function collection(UirDocument $document, array &$diagnostics, EmitOptions $options): array
    {
        if ($options->yaml) {
            // Postman reads JSON only, so a YAML "collection" is a file it refuses to import.
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'postman.yaml-ignored',
                message: 'A Postman collection has no YAML form; wrote JSON.',
            );
        }

        // Default options on purpose: a collection is a view of the PUBLISHED contract, so it must read
        // exactly the array the OpenAPI artifact does. Passing the caller's through would let
        // `mockFakerKey` inject hint members into the schemas the example factory then reads.
        $oas = $this->oas32->toOpenApiArray($document, new EmitOptions);

        $components = Arr::stringKeyed(is_array($oas['components'] ?? null) ? $oas['components'] : []);
        $info = Arr::stringKeyed(is_array($oas['info'] ?? null) ? $oas['info'] : []);
        $servers = is_array($oas['servers'] ?? null) ? array_values($oas['servers']) : [];

        $variables = $this->urls->variables($servers, $components, $diagnostics);
        $operations = $this->operations($oas, $components, $diagnostics);

        $collection = [
            'info' => $this->info($info, $servers, $variables),
            'item' => $this->tree($oas, $operations),
        ];

        $auth = $this->documentAuth($oas, $components, $diagnostics);
        if ($auth !== null) {
            $collection['auth'] = $auth;
        }

        $collection['variable'] = $variables;

        $this->reportDropped($oas, $diagnostics);

        return $collection;
    }

    /**
     * @param  array<string, mixed>  $info
     * @param  list<mixed>  $servers
     * @param  list<array<string, mixed>>  $variables
     * @return array<string, mixed>
     */
    private function info(array $info, array $servers, array $variables): array
    {
        $out = ['name' => is_string($info['title'] ?? null) ? $info['title'] : 'API'];

        $description = Description::collection($info, $servers, $variables);
        if ($description !== '') {
            $out['description'] = $description;
        }

        $out['schema'] = self::SCHEMA_URL;

        return $out;
    }

    /**
     * Every operation, flattened and ordered by (path, method rank) — both pure functions of the
     * operation itself, so adding a route can never move an unrelated one.
     *
     * @param  array<string, mixed>  $oas
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return list<array{tags: list<string>, item: array<string, mixed>}>
     */
    private function operations(array $oas, array $components, array &$diagnostics): array
    {
        $paths = Arr::stringKeyed(is_array($oas['paths'] ?? null) ? $oas['paths'] : []);

        $templates = array_map(strval(...), array_keys($paths));
        sort($templates, SORT_STRING);

        $out = [];
        foreach ($templates as $template) {
            // A path item may be written as a `$ref`, and one describes the same requests as the same
            // path item written inline — a collection missing a whole path because its shape was shared
            // is not a smaller collection, it is a wrong one.
            [$pathItem, , $unresolved] = Refs::follow($oas, Arr::stringKeyed(is_array($paths[$template] ?? null) ? $paths[$template] : []), []);

            if ($unresolved !== null) {
                continue;
            }

            $shared = is_array($pathItem['parameters'] ?? null) ? array_values($pathItem['parameters']) : [];

            foreach (PathItem::METHODS as $method) {
                if (! is_array($pathItem[$method] ?? null)) {
                    continue;
                }

                $operation = Arr::stringKeyed($pathItem[$method]);
                $out[] = [
                    'tags' => $this->tagsOf($operation),
                    'item' => $this->item($method, $template, $operation, $shared, $components, $oas, $diagnostics),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function tagsOf(array $operation): array
    {
        $tags = is_array($operation['tags'] ?? null) ? $operation['tags'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $t): ?string => is_string($t) && $t !== '' ? $t : null,
            $tags,
        )));
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  list<mixed>  $shared  path-item level parameters
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $oas
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function item(string $method, string $template, array $operation, array $shared, array $components, array $oas, array &$diagnostics): array
    {
        $signature = strtoupper($method).' '.$template;
        $parameters = $this->urls->merge($shared, is_array($operation['parameters'] ?? null) ? array_values($operation['parameters']) : [], $components);

        $body = $this->body($operation, $components, $signature, $diagnostics);
        $request = [];

        $auth = $this->operationAuth($operation, $oas, $components, $signature, $diagnostics);
        if ($auth !== null) {
            $request['auth'] = $auth;
        }

        $request['method'] = strtoupper($method);
        $request['header'] = $this->urls->headers($parameters, $operation, $body['contentType'] ?? null, $components);

        if ($body['body'] !== null) {
            $request['body'] = $body['body'];
        }

        $request['url'] = $this->urls->url($template, $parameters, $components, $signature, $diagnostics);

        $description = Description::request($operation);
        if ($description !== '') {
            $request['description'] = $description;
        }

        $item = ['name' => $this->name($method, $template, $operation), 'request' => $request];

        $responses = $this->responses($operation, $components, $request, $signature, $diagnostics);
        if ($responses !== []) {
            $item['response'] = $responses;
        }

        return $item;
    }

    /**
     * The request name. No disambiguating suffix: two operations may share a summary, and a `_2`
     * would make one request's name depend on which was met first.
     *
     * @param  array<string, mixed>  $operation
     */
    private function name(string $method, string $template, array $operation): string
    {
        foreach (['summary', 'operationId'] as $key) {
            if (is_string($operation[$key] ?? null) && $operation[$key] !== '') {
                return $operation[$key];
            }
        }

        return strtoupper($method).' '.$template;
    }

    /**
     * @param  array<string, mixed>  $oas
     * @param  list<array{tags: list<string>, item: array<string, mixed>}>  $operations
     * @return list<array<string, mixed>>
     */
    private function tree(array $oas, array $operations): array
    {
        return (new Folders)->tree(
            is_array($oas['tags'] ?? null) ? array_values($oas['tags']) : [],
            $operations,
        );
    }

    /**
     * @param  array<string, mixed>  $oas
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    private function documentAuth(array $oas, array $components, array &$diagnostics): ?array
    {
        $security = $this->securityOf($oas);

        // No document-level security: omit `auth` entirely. An explicit `noauth` would override
        // nothing and would read as a claim the document never made.
        if ($security === []) {
            return null;
        }

        $this->reportAuth($security, $components, null, $diagnostics);

        return $this->resolveAuth($security, $components);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $oas
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    private function operationAuth(array $operation, array $oas, array $components, string $signature, array &$diagnostics): ?array
    {
        if (! isset($operation['security']) || ! is_array($operation['security'])) {
            return null;
        }

        $security = array_values($operation['security']);

        // `security: []` is the one case where an explicit noauth says something true.
        if ($security === []) {
            return ['type' => 'noauth'];
        }

        $this->reportAuth($security, $components, $signature, $diagnostics);

        $auth = $this->resolveAuth($security, $components);

        // Compare the RESOLVED blocks: an operation that merely restates the document default adds
        // nothing, while one differing only in scopes still falls out as an override.
        return $auth === $this->resolveAuth($this->securityOf($oas), $components) ? null : $auth;
    }

    /**
     * @param  array<string, mixed>  $oas
     * @return list<mixed>
     */
    private function securityOf(array $oas): array
    {
        return is_array($oas['security'] ?? null) ? array_values($oas['security']) : [];
    }

    /**
     * The Postman auth block a requirement list resolves to. Pure, so an operation can be compared
     * against the document default without raising the document's diagnostics a second time.
     *
     * @param  list<mixed>  $security
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>|null
     */
    private function resolveAuth(array $security, array $components): ?array
    {
        $requirement = $this->firstRequirement($security);
        $schemes = $this->schemesOf($components);

        $key = $requirement === [] ? null : $this->auth->preferred($requirement, $schemes);
        if ($key === null) {
            return null;
        }

        $scopes = array_values(array_filter(
            is_array($requirement[$key] ?? null) ? $requirement[$key] : [],
            is_string(...),
        ));

        return $this->auth->block($key, Arr::stringKeyed(is_array($schemes[$key] ?? null) ? $schemes[$key] : []), $scopes);
    }

    /**
     * The requirement the collection carries: the FIRST in the list. A requirement's position is
     * authored — conventionally preferred-first — so unlike a map's key order it may decide.
     *
     * @param  list<mixed>  $security
     * @return array<string, mixed>
     */
    private function firstRequirement(array $security): array
    {
        return Arr::stringKeyed(is_array($security[0] ?? null) ? $security[0] : []);
    }

    /**
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     */
    private function schemesOf(array $components): array
    {
        return Arr::stringKeyed(is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : []);
    }

    /**
     * @param  list<mixed>  $security
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     */
    private function reportAuth(array $security, array $components, ?string $signature, array &$diagnostics): void
    {
        $requirement = $this->firstRequirement($security);
        if ($requirement === []) {
            return;
        }

        $schemes = $this->schemesOf($components);
        $key = $this->auth->preferred($requirement, $schemes);

        $names = array_map(strval(...), array_keys($requirement));
        sort($names, SORT_STRING);

        if ($key === null) {
            foreach ($names as $name) {
                $scheme = Arr::stringKeyed(is_array($schemes[$name] ?? null) ? $schemes[$name] : []);
                $type = is_string($scheme['type'] ?? null) ? $scheme['type'] : 'an unknown type';

                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'postman.auth-unsupported',
                    message: sprintf(
                        'Security scheme `%s` (%s) has no Postman equivalent; requests are sent unauthenticated and the credential must be added by hand.',
                        $name,
                        $type,
                    ),
                    routeSignature: $signature,
                );
            }

            return;
        }

        if (count($names) > 1) {
            $others = array_values(array_diff($names, [$key]));

            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'postman.auth-multi-scheme',
                message: sprintf(
                    'Requires %s together, but a Postman request carries one credential; the collection sends `%s` — supply %s yourself.',
                    implode(' and ', array_map(static fn (string $s): string => sprintf('`%s`', $s), $names)),
                    $key,
                    implode(' and ', array_map(static fn (string $s): string => sprintf('`%s`', $s), $others)),
                ),
                routeSignature: $signature,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return array{body: array<string, mixed>|null, contentType: string|null}
     */
    private function body(array $operation, array $components, string $signature, array &$diagnostics): array
    {
        $written = is_array($operation['requestBody'] ?? null) ? Arr::stringKeyed($operation['requestBody']) : [];
        [$requestBody, , $unresolved] = Ref::follow($written, $components);

        $content = $unresolved === null && is_array($requestBody['content'] ?? null)
            ? Arr::stringKeyed($requestBody['content'])
            : [];

        if ($content === []) {
            return ['body' => null, 'contentType' => null];
        }

        $mediaType = Body::preferred(array_map(strval(...), array_keys($content)), self::MEDIA_PREFERENCE);
        $media = Arr::stringKeyed(is_array($content[$mediaType] ?? null) ? $content[$mediaType] : []);

        return [
            'body' => Body::of($mediaType, $media, $components, $this->examples, $signature, $diagnostics),
            'contentType' => $mediaType,
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $request
     * @param  list<Diagnostic>  $diagnostics
     * @return list<array<string, mixed>>
     */
    private function responses(array $operation, array $components, array $request, string $signature, array &$diagnostics): array
    {
        $responses = Arr::stringKeyed(is_array($operation['responses'] ?? null) ? $operation['responses'] : []);

        // Only numeric statuses: `default`/`2XX` cannot fill Postman's integer `code`, and the OpenAPI
        // artifact still carries them, so dropping them here loses nothing a consumer can act on.
        $codes = array_values(array_filter(array_map(strval(...), array_keys($responses)), ctype_digit(...)));
        sort($codes, SORT_STRING);

        $kept = array_slice($codes, 0, self::MAX_EXAMPLES);

        if (count($codes) > count($kept)) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'postman.examples-truncated',
                message: sprintf(
                    'Documents %d responses; the collection saves the first %d by status (%s) to stay navigable.',
                    count($codes),
                    count($kept),
                    implode(', ', $kept),
                ),
                routeSignature: $signature,
            );
        }

        $out = [];
        foreach ($kept as $code) {
            $response = is_array($responses[$code] ?? null) ? Arr::stringKeyed($responses[$code]) : [];
            $out[] = SavedExample::of((int) $code, $response, $components, $request, $this->examples);
        }

        return $out;
    }

    /**
     * Things the format has nowhere to put. Both are narrow — a document either declares webhooks and
     * callbacks or it does not — so the reader who sees one can act on it.
     *
     * @param  array<string, mixed>  $oas
     * @param  list<Diagnostic>  $diagnostics
     */
    private function reportDropped(array $oas, array &$diagnostics): void
    {
        $webhooks = Arr::stringKeyed(is_array($oas['webhooks'] ?? null) ? $oas['webhooks'] : []);
        if ($webhooks !== []) {
            $names = array_map(strval(...), array_keys($webhooks));
            sort($names, SORT_STRING);

            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'postman.webhooks-dropped',
                message: sprintf(
                    'A Postman collection describes requests you send, so it cannot carry the webhooks this API delivers (%s).',
                    implode(', ', $names),
                ),
            );
        }

        $paths = Arr::stringKeyed(is_array($oas['paths'] ?? null) ? $oas['paths'] : []);
        $withCallbacks = [];
        foreach ($paths as $template => $written) {
            // Followed for the same reason `operations()` follows it: a shared path item declares the
            // same callbacks, and a warning that fired only for the inline spelling is a warning whose
            // presence depends on how the document was written.
            $pathItem = is_array($written) ? Refs::follow($oas, Arr::stringKeyed($written), [])[0] : null;

            foreach (PathItem::METHODS as $method) {
                $operation = is_array($pathItem) && is_array($pathItem[$method] ?? null) ? $pathItem[$method] : null;
                if ($operation !== null && is_array($operation['callbacks'] ?? null) && $operation['callbacks'] !== []) {
                    $withCallbacks[] = strtoupper($method).' '.$template;
                }
            }
        }

        sort($withCallbacks, SORT_STRING);
        foreach ($withCallbacks as $signature) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'postman.callbacks-dropped',
                message: 'The callbacks this operation declares have no Postman equivalent; they are not in the collection.',
                routeSignature: $signature,
            );
        }
    }
}
