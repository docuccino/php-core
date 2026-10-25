<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Contract\Refs;
use Docuccino\Core\Document\NodeIdentity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Document\UseSites;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Identity\IdentityGenerator;

/**
 * The {@see DocumentNode}s of a document. Read off the LOWERED document ({@see UseSites}), so a use's
 * id is found beside its `$ref` whichever UIR minor wrote it, and lowering moves nothing in a list, so
 * every pointer is still a position in the bytes that were read.
 *
 * A digest is of the node as written, canonicalised on its own and with every `provenance` record
 * stripped. A `$ref` is a node's bytes like any other, so a use of a shared response digests the
 * reference and not the component behind it; the shared response or parameter is listed as a node of its
 * own, keyed by that reference (`#/components/responses/NotFound`), and its digest is the one an edit to
 * it moves. Path-item-level parameters are listed under every
 * operation they apply to and do not override, keyed by that operation and pointing where they are
 * written. A path written as a `$ref` to `components.pathItems` lists the operations it resolves to,
 * pointing there; a callback's operations are part of the operation declaring them, and a path item no
 * path references is not an endpoint, so neither is listed on its own.
 *
 * Both of those list one written node many times over, so the index can outgrow the document by any
 * factor: {@see admit()} is the bound, counted before anything is digested. Within it, a path item is
 * read and a node digested once, however often either is listed.
 *
 * @internal
 *
 * @phpstan-type Listed array{node: mixed, pointer: string}
 * @phpstan-type Planned array{method: string, operation: array<string, mixed>, pointer: string, parameters: array<string, Listed>, responses: array<array-key, Listed>}
 * @phpstan-type Plan array{shared: array<string, Listed>, operations: list<Planned>}
 */
final class NodeManifest
{
    /** The fewest nodes a document may index to, so a small one sharing a path item is never refused. */
    private const int MIN_NODES = 10_000;

    private readonly Canonicalizer $canonicalizer;

    private readonly CanonicalJsonSerializer $serializer;

    private readonly UirEmitter $emitter;

    private readonly IdentityGenerator $identities;

    /** @var array<string, mixed> */
    private array $document = [];

    /** @var list<DocumentNode> */
    private array $nodes = [];

    /**
     * Each path item's operations, by where the item is written.
     *
     * @var array<string, Plan>
     */
    private array $plans = [];

    /**
     * How many nodes each path item lists, by where the item is written.
     *
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * Each listed node's digest, by where the node is written.
     *
     * @var array<string, string>
     */
    private array $digests = [];

    public function __construct()
    {
        $this->canonicalizer = new Canonicalizer;
        $this->serializer = new CanonicalJsonSerializer;
        $this->emitter = new UirEmitter($this->canonicalizer, $this->serializer);
        $this->identities = new IdentityGenerator;
    }

    /**
     * Refuses $document where it would index to more nodes than its $length in bytes, or than
     * {@see MIN_NODES} for a small one. A document listing every node where it is written cannot spell
     * more, so past that the index is only repeating what the document shares.
     *
     * @param  array<string, mixed>  $document  as read, in either UIR minor's form
     *
     * @throws UnreadableDocumentException
     */
    public function admit(array $document, int $length): void
    {
        $count = $this->count($document);
        $limit = max(self::MIN_NODES, $length);

        if ($count > $limit) {
            throw new UnreadableDocumentException(ReadFailure::NodeExpansion, sprintf(
                'The document expands to %d nodes from %d bytes, more than the %d it may: the path items its paths share, and the parameters a path item lists for every operation, repeat far more often than any document needs, so it was refused rather than indexed.',
                $count,
                $length,
                $limit,
            ));
        }
    }

    /**
     * How many nodes {@see of()} lists for $document, counted without listing or digesting any.
     *
     * @param  array<string, mixed>  $document  as read, in either UIR minor's form
     */
    public function count(array $document): int
    {
        $this->start($document);

        $count = 0;
        foreach ($this->pathItems() as [, , $item, $segments]) {
            $at = Pointer::of($segments);

            if (! isset($this->counts[$at])) {
                $plan = $this->plan($item, $segments);
                $this->counts[$at] = 0;
                foreach ($plan['operations'] as $planned) {
                    $parameters = count($planned['parameters']) + count($plan['shared']) - count(array_intersect_key($planned['parameters'], $plan['shared']));
                    $this->counts[$at] += 1 + $parameters + count($planned['responses']);
                }
            }

            $count += $this->counts[$at];
        }

        $components = self::map($this->document['components'] ?? null);
        $extension = self::map($this->document['x-docuccino'] ?? null);

        return $count
            + count(self::map($components['schemas'] ?? null))
            + count(self::map($components['parameters'] ?? null))
            + count(self::map($components['responses'] ?? null))
            + count(array_filter(self::map(self::map($extension['content'] ?? null)['pages'] ?? null), is_array(...)))
            + count(array_filter(self::map($extension['workflows'] ?? null), is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $document  as read, in either UIR minor's form
     * @return list<DocumentNode>
     */
    public function of(array $document): array
    {
        $this->start($document);

        foreach ($this->pathItems() as [$member, $name, $item, $segments]) {
            $path = $member === 'webhooks' ? 'webhook:'.$name : $this->identities->normalizePathTemplate($name);

            $plan = $this->plan($item, $segments);
            foreach ($plan['operations'] as $planned) {
                $this->operation($planned['method'].' '.$path, $planned, $plan['shared']);
            }
        }

        $components = self::map($this->document['components'] ?? null);
        foreach (self::map($components['schemas'] ?? null) as $name => $schema) {
            $name = (string) $name;
            $this->nodes[] = new DocumentNode(
                NodeKind::Schema,
                $this->identity($schema, []),
                $name,
                Pointer::of(['components', 'schemas', $name]),
                is_array($schema) ? self::string($schema, 'title') : null,
                is_array($schema) && ($schema['deprecated'] ?? null) === true,
                $this->digest(['components' => ['schemas' => [$name => $schema]]], ['components', 'schemas', $name]),
            );
        }

        // A shared response or parameter is a node of its own, keyed by the reference its uses write, so
        // an edit to it moves its digest even though every use's digest is only of that reference.
        foreach (self::map($components['parameters'] ?? null) as $name => $parameter) {
            $segments = ['components', 'parameters', (string) $name];
            $resolved = $this->resolved($parameter) ?? [];
            $this->nodes[] = new DocumentNode(
                NodeKind::Parameter,
                $this->identity($parameter, $resolved),
                '#'.Pointer::of($segments),
                Pointer::of($segments),
                self::string($resolved, 'name'),
                ($resolved['deprecated'] ?? null) === true,
                $this->digest(['components' => ['parameters' => [(string) $name => $parameter]]], $segments),
            );
        }
        foreach (self::map($components['responses'] ?? null) as $name => $response) {
            $segments = ['components', 'responses', (string) $name];
            $resolved = $this->resolved($response) ?? [];
            $this->nodes[] = new DocumentNode(
                NodeKind::Response,
                $this->identity($response, $resolved),
                '#'.Pointer::of($segments),
                Pointer::of($segments),
                self::string($resolved, 'description'),
                false,
                $this->digest(['components' => ['responses' => [(string) $name => $response]]], $segments),
            );
        }

        $extension = self::map($this->document['x-docuccino'] ?? null);
        $this->pages(self::map($extension['content'] ?? null)['pages'] ?? null);
        $this->workflows($extension['workflows'] ?? null);

        $rank = static fn (DocumentNode $node): int => (int) array_search($node->kind, NodeKind::cases(), true);
        usort($this->nodes, static fn (DocumentNode $a, DocumentNode $b): int => [$rank($a), $a->key, $a->pointer] <=> [$rank($b), $b->key, $b->pointer]);

        return $this->nodes;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function start(array $document): void
    {
        $this->document = UseSites::lower($document);
        $this->nodes = [];
        $this->plans = [];
        $this->counts = [];
        $this->digests = [];
    }

    /**
     * Every path and webhook, with the path item it lists operations from and where that item is written
     * — which, for a path written as a `$ref`, is where the reference lands. One landing nowhere lists
     * nothing.
     *
     * @return iterable<array{string, string, array<string, mixed>, list<string>}>
     */
    private function pathItems(): iterable
    {
        foreach (['paths', 'webhooks'] as $member) {
            foreach (self::map($this->document[$member] ?? null) as $name => $item) {
                if (! is_array($item)) {
                    continue;
                }

                /** @var array<string, mixed> $item */
                $segments = [$member, (string) $name];

                if (is_string($item['$ref'] ?? null)) {
                    [$item, $segments, $dangling] = Refs::follow($this->document, $item, $segments);
                    if ($dangling !== null) {
                        continue;
                    }
                }

                yield [$member, (string) $name, $item, $segments];
            }
        }
    }

    /**
     * The parameters the path item written at $segments shares, and its operations, each with its own
     * parameters and its responses — read once, however many paths reference the item. The two lists of
     * parameters are kept apart: merged, a path item with many operations and many parameters would
     * hold their product.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $segments
     * @return Plan
     */
    private function plan(array $item, array $segments): array
    {
        $at = Pointer::of($segments);
        if (isset($this->plans[$at])) {
            return $this->plans[$at];
        }

        $shared = $this->parameters($item['parameters'] ?? null, [...$segments, 'parameters']);

        $operations = [];
        foreach (PathItem::METHODS as $method) {
            $operations[$method] = [$item[$method] ?? null, [...$segments, $method]];
        }
        foreach (self::map($item['additionalOperations'] ?? null) as $method => $operation) {
            $operations[(string) $method] = [$operation, [...$segments, 'additionalOperations', (string) $method]];
        }

        $plan = [];
        foreach ($operations as $method => [$operation, $operationAt]) {
            if (! is_array($operation)) {
                continue;
            }

            /** @var array<string, mixed> $operation */
            $responses = [];
            foreach (self::map($operation['responses'] ?? null) as $status => $response) {
                $responses[$status] = ['node' => $response, 'pointer' => Pointer::of([...$operationAt, 'responses', (string) $status])];
            }

            $plan[] = [
                'method' => strtoupper((string) $method),
                'operation' => $operation,
                'pointer' => Pointer::of($operationAt),
                'parameters' => $this->parameters($operation['parameters'] ?? null, [...$operationAt, 'parameters']),
                'responses' => $responses,
            ];
        }

        return $this->plans[$at] = ['shared' => $shared, 'operations' => $plan];
    }

    /**
     * An operation's node under $key, then its parameters' — its own, and those its path item shares
     * that it does not restate — and its responses'.
     *
     * @param  Planned  $planned
     * @param  array<string, Listed>  $shared
     */
    private function operation(string $key, array $planned, array $shared): void
    {
        $operation = $planned['operation'];

        $this->nodes[] = new DocumentNode(
            NodeKind::Operation,
            NodeIdentity::inArray($operation),
            $key,
            $planned['pointer'],
            self::string($operation, 'summary') ?? self::string($operation, 'operationId'),
            ($operation['deprecated'] ?? null) === true,
            $this->digests[$planned['pointer']] ??= $this->digest(['paths' => ['/' => ['get' => $operation]]], ['paths', '/', 'get']),
        );

        foreach ($planned['parameters'] + $shared as $location => ['node' => $parameter, 'pointer' => $pointer]) {
            $resolved = $this->resolved($parameter) ?? [];

            $this->nodes[] = new DocumentNode(
                NodeKind::Parameter,
                $this->identity($parameter, $resolved),
                $key.' '.$location,
                $pointer,
                self::string($resolved, 'name'),
                ($resolved['deprecated'] ?? null) === true,
                $this->digests[$pointer] ??= $this->digest(['paths' => ['/' => ['get' => ['parameters' => [$parameter]]]]], ['paths', '/', 'get', 'parameters', 0]),
            );
        }

        foreach ($planned['responses'] as $status => ['node' => $response, 'pointer' => $pointer]) {
            $resolved = $this->resolved($response) ?? [];

            $this->nodes[] = new DocumentNode(
                NodeKind::Response,
                $this->identity($response, $resolved),
                $key.' '.$status,
                $pointer,
                self::string($resolved, 'description'),
                false,
                $this->digests[$pointer] ??= $this->digest(['paths' => ['/' => ['get' => ['responses' => [$status => $response]]]]], ['paths', '/', 'get', 'responses', (string) $status]),
            );
        }
    }

    /**
     * $parameters keyed by `in:name`, each with the parameter and where it is written. A `$ref` is keyed
     * by what it resolves to, and by the reference itself where that is nothing. A second parameter under
     * one key is a document OpenAPI calls invalid; the first is the one listed.
     *
     * @param  list<string>  $segments  where the list is written
     * @return array<string, Listed>
     */
    private function parameters(mixed $parameters, array $segments): array
    {
        $keyed = [];

        foreach (is_array($parameters) ? $parameters : [] as $index => $parameter) {
            $resolved = $this->resolved($parameter);
            $location = $resolved === null && is_array($parameter) && is_string($parameter['$ref'] ?? null)
                ? '$ref:'.$parameter['$ref']
                : (self::string($resolved ?? [], 'in') ?? '').':'.(self::string($resolved ?? [], 'name') ?? '');

            $keyed[$location] ??= ['node' => $parameter, 'pointer' => Pointer::of([...$segments, (string) $index])];
        }

        return $keyed;
    }

    private function pages(mixed $pages): void
    {
        foreach (is_array($pages) ? $pages : [] as $index => $page) {
            if (! is_array($page)) {
                continue;
            }

            /** @var array<string, mixed> $page */
            $this->nodes[] = new DocumentNode(
                NodeKind::Page,
                self::string($page, 'id'),
                self::string($page, 'slug') ?? '',
                Pointer::of(['x-docuccino', 'content', 'pages', (string) $index]),
                self::string($page, 'title'),
                false,
                $this->digest(['x-docuccino' => ['content' => ['pages' => [$page]]]], ['x-docuccino', 'content', 'pages', 0]),
            );
        }
    }

    private function workflows(mixed $workflows): void
    {
        foreach (is_array($workflows) ? $workflows : [] as $index => $workflow) {
            if (! is_array($workflow)) {
                continue;
            }

            /** @var array<string, mixed> $workflow */
            $this->nodes[] = new DocumentNode(
                NodeKind::Workflow,
                null,
                self::string($workflow, 'id') ?? '',
                Pointer::of(['x-docuccino', 'workflows', (string) $index]),
                self::string($workflow, 'summary'),
                false,
                $this->digest(['x-docuccino' => ['workflows' => [$workflow]]], ['x-docuccino', 'workflows', 0]),
            );
        }
    }

    /**
     * A node's own id, or — for a `$ref` that carries none, as an OpenAPI export's use of a shared
     * parameter does — the id of what it resolves to.
     *
     * @param  array<string, mixed>  $resolved
     */
    private function identity(mixed $node, array $resolved): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        /** @var array<string, mixed> $node */
        return NodeIdentity::inArray($node) ?? (isset($node['$ref']) ? NodeIdentity::inArray($resolved) : null);
    }

    /**
     * $node with its `$ref` chain followed, or null where it is no object or the chain goes nowhere.
     *
     * @return array<string, mixed>|null
     */
    private function resolved(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        /** @var array<string, mixed> $node */
        [$resolved, , $dangling] = Refs::follow($this->document, $node, []);

        return $dangling === null ? $resolved : null;
    }

    /**
     * The SHA-256 of the canonical bytes of the node at $segments in $holder — a document holding that
     * one node, provenance stripped, at a position whose canonical form depends on nothing around it.
     *
     * @param  array<string, mixed>  $holder
     * @param  list<string|int>  $segments
     */
    private function digest(array $holder, array $segments): string
    {
        $stripped = $this->emitter->levelled($holder, ProvenanceLevel::None);

        return hash('sha256', $this->serializer->serialize(self::read($this->canonicalizer->canonicalize($stripped), $segments)));
    }

    /**
     * @param  list<string|int>  $segments
     */
    private static function read(mixed $node, array $segments): mixed
    {
        foreach ($segments as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? null) : null;
        }

        return $node;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function string(array $node, string $member): ?string
    {
        return is_string($node[$member] ?? null) ? $node[$member] : null;
    }
}
