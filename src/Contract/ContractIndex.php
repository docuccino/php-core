<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Docuccino\Core\Diff\Change;
use Docuccino\Core\Document\NodeIdentity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\JsonValue;
use JsonException;
use stdClass;

/**
 * A generated document, indexed for lookup by (method, concrete request path), by webhook name, and by
 * node id.
 *
 * The entry point for contract testing: given a UIR artifact and one observed exchange — or one
 * payload dispatched for a documented webhook — {@see ContractChecker} answers whether it matches what
 * the document promises, and {@see ProvenanceTrail} answers who promised it. Framework-neutral
 * throughout — an adapter supplies the observation, this package supplies the verdict.
 *
 * It reads the raw decoded document rather than {@see UirDocument} because
 * everything downstream — JSON Schema validation, the provenance trail, the example audit — needs the
 * schema keyword space and the `x-docuccino` members verbatim, which the typed model folds away.
 */
final class ContractIndex
{
    private ?object $graph = null;

    /** @var array<string, list<string>>|null */
    private ?array $identities = null;

    /**
     * @param  array<string, mixed>  $document
     * @param  list<ContractOperation>  $operations
     * @param  list<ContractWebhook>  $webhooks
     * @param  string  $json  the document's own JSON text, kept because associative decoding cannot
     *                        tell an empty object from an empty array and JSON Schema very much can
     */
    private function __construct(
        private readonly array $document,
        private readonly array $operations,
        private readonly array $webhooks,
        private readonly string $json,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  string|null  $json  the document's original JSON text, when the caller still has it
     */
    public static function fromArray(array $document, ?string $json = null): self
    {
        return new self(
            $document,
            self::index($document),
            self::indexWebhooks($document),
            $json ?? (string) json_encode($document),
        );
    }

    /**
     * @throws JsonException when $json is not a JSON object
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('The contract document is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded, $json);
    }

    /**
     * Whether this is a UIR document rather than a plain OpenAPI export. Only UIR carries
     * `x-docuccino.provenance`, so a failure message says less when this is false.
     */
    public function isUir(): bool
    {
        return isset($this->document['uir']);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->document;
    }

    /**
     * The document as an object graph, which is what JSON Schema validation needs: `properties: {}`
     * and `properties: []` mean different things and only the object form keeps them apart.
     *
     * An empty graph where the kept text is not JSON, which {@see fromArray()} reaches whenever
     * `json_encode` refuses the array it was handed — invalid UTF-8, an `INF` — and hands over `''`.
     *
     * @internal
     */
    public function graph(): object
    {
        if ($this->graph !== null) {
            return $this->graph;
        }

        $decoded = json_decode($this->json, false);

        return $this->graph = is_object($decoded) ? $decoded : new stdClass;
    }

    /**
     * The document as the typed model, for the SEMANTIC DIFF. Read off {@see graph()} rather than off
     * the associative copy this class indexes, and for the same reason validation is: only the object
     * form tells `{}` from `[]`, and at a compared keyword those are two different values.
     *
     * @internal
     */
    public function comparable(): UirDocument
    {
        return UirDocument::fromArray(Arr::stringKeyed((array) JsonValue::normalize($this->graph())));
    }

    /**
     * Every documented operation, ordered by path then by the canonical method order — a function of
     * the document's content, so two runs over the same artifact list them identically.
     *
     * @return list<ContractOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /** The operation with this id, or null. Ids survive a path rename; path strings do not. */
    public function operation(string $id): ?ContractOperation
    {
        foreach ($this->operations as $operation) {
            if ($operation->id === $id) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * The operation a concrete request hit, or null when the document describes no such endpoint.
     *
     * Where several templates match, the most specific wins ({@see PathTemplate::literalMask()}).
     */
    public function match(string $method, string $path): ?ContractOperation
    {
        $method = strtoupper($method);
        $best = null;
        $bestMask = '';

        foreach ($this->operations as $operation) {
            if ($operation->method !== $method || $operation->bind($path) === null) {
                continue;
            }

            $mask = $operation->literalMask();
            if ($best === null || $mask > $bestMask) {
                $best = $operation;
                $bestMask = $mask;
            }
        }

        return $best;
    }

    /**
     * Every documented webhook, ordered by name then by the canonical method order — a function of the
     * document's content, so two runs over the same artifact list them identically.
     *
     * @return list<ContractWebhook>
     *
     * @internal
     */
    public function webhooks(): array
    {
        return $this->webhooks;
    }

    /**
     * The webhooks published under this name, in canonical method order. A name is the whole lookup:
     * {@see match()} is inbound-only by construction, and a webhook has no path to match on.
     *
     * @return list<ContractWebhook>
     */
    public function webhooksNamed(string $name): array
    {
        return array_values(array_filter(
            $this->webhooks,
            static fn (ContractWebhook $webhook): bool => $webhook->name === $name,
        ));
    }

    /**
     * The distinct names the document publishes webhooks under, sorted — what a "no such webhook"
     * message offers instead.
     *
     * @return list<string>
     *
     * @internal
     */
    public function webhookNames(): array
    {
        $names = [];
        foreach ($this->webhooks as $webhook) {
            $names[$webhook->name] = true;
        }

        $sorted = array_keys($names);
        sort($sorted, SORT_STRING);

        return $sorted;
    }

    /**
     * The path templates whose path item is written as a `$ref` the document does not define, as
     * `template => the reference`, sorted. Empty where every reference lands.
     *
     * A path item that points nowhere has no methods to read, so the path publishes no operations —
     * and every lookup and every count here would then report exactly what an undocumented route
     * reports. This is the difference: the document DOES describe that path, and a typo in one
     * pointer is a broken document rather than a missing route. An index cannot fail an assertion, so
     * it keeps the fact and {@see ContractMessages::undocumented()} says it.
     *
     * @return array<string, string>
     *
     * @internal
     */
    public function unresolvedPaths(): array
    {
        return self::unresolvedPathItems($this->document, 'paths');
    }

    /**
     * The same for `webhooks`, as `name => the reference` — the outbound half of one defect, and
     * {@see ContractMessages::undocumentedWebhook()} says it.
     *
     * @return array<string, string>
     *
     * @internal
     */
    public function unresolvedWebhooks(): array
    {
        return self::unresolvedPathItems($this->document, 'webhooks');
    }

    /**
     * Whether this artifact's FORMAT has a `webhooks` member at all: OpenAPI 3.0 defines none, so a
     * document downlevelled to it dropped every webhook it had. That is a different answer from
     * "documents no webhooks", and a caller owes its reader the difference.
     */
    public function supportsWebhooks(): bool
    {
        return ! str_starts_with($this->openApiVersion(), '3.0');
    }

    /**
     * The OpenAPI version the document declares, empty when it declares none.
     *
     * @internal
     */
    public function openApiVersion(): string
    {
        $version = $this->document['openapi'] ?? null;

        return is_string($version) ? $version : '';
    }

    /**
     * Where every node carrying an `x-docuccino` id lives, as `id => pointer segments`. Both id forms
     * are read ({@see NodeIdentity}), so an OpenAPI export with flat ids maps as well as UIR does.
     *
     * This is what turns a {@see Change} — which names a node by id and nothing
     * else — into a provenance trail: the change says what broke, the trail says who wrote it.
     *
     * @return array<string, list<string>>
     */
    public function identities(): array
    {
        return $this->identities ??= self::collectIdentities($this->document, []);
    }

    /** The provenance recorded on the node with this id, empty when there is none to read. */
    public function provenanceOf(string $id): ProvenanceTrail
    {
        $segments = $this->identities()[$id] ?? null;

        return $segments === null ? ProvenanceTrail::none() : ProvenanceTrail::at($this->document, $segments);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $segments
     * @return array<string, list<string>>
     */
    private static function collectIdentities(array $node, array $segments): array
    {
        $found = [];

        $id = NodeIdentity::inArray(Arr::stringKeyed($node));
        if ($id !== null) {
            $found[$id] = $segments;
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                // A later sibling never displaces an earlier one: the shallowest, first-sorted node
                // wins, which is a function of the document rather than of traversal luck.
                $found += self::collectIdentities($child, [...$segments, (string) $key]);
            }
        }

        return $found;
    }

    /**
     * The `paths` map, one entry per (path item, method).
     *
     * A path item may be written as a `$ref` into `components.pathItems`, so it is followed
     * ({@see Refs::follow()}) before anything is read off it — a reference is a spelling, not a
     * different contract, and an operation behind one indexes exactly as the same operation written
     * inline does. The `path` stays the USE SITE's template, which is what a request binds against;
     * only the pointer segments move to where a reader would go and look, as {@see
     * ContractOperation::responseFor()} already has them.
     *
     * @param  array<string, mixed>  $document
     * @return list<ContractOperation>
     */
    private static function index(array $document): array
    {
        $paths = $document['paths'] ?? null;

        if (! is_array($paths)) {
            return [];
        }

        $templates = array_map(strval(...), array_keys($paths));
        sort($templates);

        $operations = [];
        foreach ($templates as $template) {
            $written = $paths[$template];
            if (! is_array($written)) {
                continue;
            }

            /** @var array<string, mixed> $written */
            [$item, $at, $dangling] = Refs::follow($document, $written, ['paths', $template]);

            // Nothing to index behind a pointer that lands nowhere: which methods it publishes is
            // precisely what could not be read. {@see unresolvedPaths()} keeps the fact so the reader
            // is told the pointer is broken rather than that the route is undocumented.
            if ($dangling !== null) {
                continue;
            }

            $shared = self::parameters($document, $item['parameters'] ?? null, [...$at, 'parameters']);

            foreach (PathItem::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (! is_array($operation)) {
                    continue;
                }

                /** @var array<string, mixed> $operation */
                $segments = [...$at, $method];

                $operations[] = new ContractOperation(
                    id: NodeIdentity::inArray($operation),
                    method: strtoupper($method),
                    path: $template,
                    operation: $operation,
                    parameters: self::mergeParameters(
                        $shared,
                        self::parameters($document, $operation['parameters'] ?? null, [...$segments, 'parameters']),
                    ),
                    segments: $segments,
                );
            }
        }

        return $operations;
    }

    /**
     * The `webhooks` map, indexed the same way {@see index()} does `paths` — by a sorted key rather
     * than by the order the document happens to spell them in, and with a path item written as a
     * `$ref` followed for the same reason.
     *
     * @param  array<string, mixed>  $document
     * @return list<ContractWebhook>
     */
    private static function indexWebhooks(array $document): array
    {
        $webhooks = $document['webhooks'] ?? null;

        if (! is_array($webhooks)) {
            return [];
        }

        $names = array_map(strval(...), array_keys($webhooks));
        sort($names, SORT_STRING);

        $indexed = [];
        foreach ($names as $name) {
            $written = $webhooks[$name];
            if (! is_array($written)) {
                continue;
            }

            /** @var array<string, mixed> $written */
            [$item, $at, $dangling] = Refs::follow($document, $written, ['webhooks', $name]);

            if ($dangling !== null) {
                continue;
            }

            foreach (PathItem::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (! is_array($operation)) {
                    continue;
                }

                /** @var array<string, mixed> $operation */
                $indexed[] = new ContractWebhook(
                    id: NodeIdentity::inArray($operation),
                    name: $name,
                    method: strtoupper($method),
                    operation: $operation,
                    segments: [...$at, $method],
                );
            }
        }

        return $indexed;
    }

    /**
     * The entries of a path-item map whose `$ref` chain never lands — a name nothing defines, or a
     * cycle, which {@see Refs::follow()} reports the same way because both leave the same nothing to
     * read.
     *
     * @param  array<string, mixed>  $document
     * @param  'paths'|'webhooks'  $member
     * @return array<string, string>
     */
    private static function unresolvedPathItems(array $document, string $member): array
    {
        $map = $document[$member] ?? null;

        if (! is_array($map)) {
            return [];
        }

        $keys = array_map(strval(...), array_keys($map));
        sort($keys, SORT_STRING);

        $found = [];
        foreach ($keys as $key) {
            $node = $map[$key];

            if (! is_array($node)) {
                continue;
            }

            /** @var array<string, mixed> $node */
            $dangling = Refs::follow($document, $node, [$member, $key])[2];

            if ($dangling !== null) {
                $found[$key] = $dangling;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $segments  pointer segments addressing the parameter list itself
     * @return list<ContractParameter>
     */
    private static function parameters(array $document, mixed $value, array $segments): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (array_values($value) as $index => $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            /** @var array<string, mixed> $parameter */
            [$definition, $where, $dangling] = Refs::follow($document, $parameter, [...$segments, (string) $index]);

            // A `$ref` at a name the document does not define leaves nothing to read `name`, `in` or
            // `schema` off. Dropping it here would make the parameter simply cease to be checked, so it
            // is kept and reported: the checker fails naming the pointer.
            if ($dangling !== null) {
                $out[] = new ContractParameter(
                    name: '',
                    in: '',
                    required: false,
                    definition: $definition,
                    segments: $where,
                    label: 'the parameter at '.Pointer::of($where),
                    danglingRef: $dangling,
                );

                continue;
            }

            $name = $definition['name'] ?? null;
            $in = $definition['in'] ?? null;

            if (! is_string($name) || ! is_string($in)) {
                continue;
            }

            $out[] = new ContractParameter(
                name: $name,
                in: $in,
                required: ($definition['required'] ?? false) === true,
                definition: $definition,
                segments: $where,
            );
        }

        return $out;
    }

    /**
     * OAS parameter inheritance: an operation-level parameter replaces a path-item one with the same
     * name and location, and adds to the rest.
     *
     * @param  list<ContractParameter>  $shared
     * @param  list<ContractParameter>  $own
     * @return list<ContractParameter>
     */
    private static function mergeParameters(array $shared, array $own): array
    {
        $merged = [];
        foreach ($own as $parameter) {
            $merged[self::mergeKey($parameter)] = $parameter;
        }

        foreach ($shared as $parameter) {
            $merged[self::mergeKey($parameter)] ??= $parameter;
        }

        return array_values($merged);
    }

    /**
     * What "the same parameter" means for that merge. A dangling one has no name and no location to be
     * the same BY, so it keys on where it was written — two unresolvable `$ref`s are two findings.
     */
    private static function mergeKey(ContractParameter $parameter): string
    {
        return $parameter->danglingRef === null
            ? $parameter->in.':'.$parameter->name
            : 'at:'.Pointer::of($parameter->segments);
    }
}
