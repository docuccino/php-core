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
 * A generated document, indexed for lookup by (method, concrete request path) and by node id.
 *
 * The entry point for contract testing: given a UIR artifact and one observed exchange,
 * {@see ContractChecker} answers whether the exchange matches what the document promises, and
 * {@see ProvenanceTrail} answers who promised it. Framework-neutral throughout — an adapter supplies
 * the exchange, this package supplies the verdict.
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
     * @param  string  $json  the document's own JSON text, kept because associative decoding cannot
     *                        tell an empty object from an empty array and JSON Schema very much can
     */
    private function __construct(
        private readonly array $document,
        private readonly array $operations,
        private readonly string $json,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  string|null  $json  the document's original JSON text, when the caller still has it
     */
    public static function fromArray(array $document, ?string $json = null): self
    {
        return new self($document, self::index($document), $json ?? (string) json_encode($document));
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
            $item = $paths[$template];
            if (! is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $shared = self::parameters($document, $item['parameters'] ?? null, ['paths', $template, 'parameters']);

            foreach (PathItem::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (! is_array($operation)) {
                    continue;
                }

                /** @var array<string, mixed> $operation */
                $segments = ['paths', $template, $method];

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
            [$definition, $where] = Refs::follow($document, $parameter, [...$segments, (string) $index]);

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
            $merged[$parameter->in.':'.$parameter->name] = $parameter;
        }

        foreach ($shared as $parameter) {
            $merged[$parameter->in.':'.$parameter->name] ??= $parameter;
        }

        return array_values($merged);
    }
}
