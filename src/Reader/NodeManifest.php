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
 * @internal
 */
final class NodeManifest
{
    private readonly Canonicalizer $canonicalizer;

    private readonly CanonicalJsonSerializer $serializer;

    private readonly UirEmitter $emitter;

    private readonly IdentityGenerator $identities;

    /** @var array<string, mixed> */
    private array $document = [];

    /** @var list<DocumentNode> */
    private array $nodes = [];

    public function __construct()
    {
        $this->canonicalizer = new Canonicalizer;
        $this->serializer = new CanonicalJsonSerializer;
        $this->emitter = new UirEmitter($this->canonicalizer, $this->serializer);
        $this->identities = new IdentityGenerator;
    }

    /**
     * @param  array<string, mixed>  $document  as read, in either UIR minor's form
     * @return list<DocumentNode>
     */
    public function of(array $document): array
    {
        $this->document = UseSites::lower($document);
        $this->nodes = [];

        foreach (['paths', 'webhooks'] as $member) {
            foreach (self::map($this->document[$member] ?? null) as $name => $item) {
                $this->pathItem($member, (string) $name, $item);
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

    private function pathItem(string $member, string $name, mixed $item): void
    {
        if (! is_array($item)) {
            return;
        }

        /** @var array<string, mixed> $item */
        $segments = [$member, $name];

        // A path item may be a reference to one under `components.pathItems`; its operations are then
        // written there, and that is where they are pointed at.
        if (is_string($item['$ref'] ?? null)) {
            [$resolved, $target, $dangling] = Refs::follow($this->document, $item, $segments);
            if ($dangling !== null) {
                return;
            }

            $item = $resolved;
            $segments = $target;
        }

        $shared = $this->parameters($item['parameters'] ?? null, [...$segments, 'parameters']);

        $operations = [];
        foreach (PathItem::METHODS as $method) {
            $operations[$method] = [$item[$method] ?? null, [...$segments, $method]];
        }
        foreach (self::map($item['additionalOperations'] ?? null) as $method => $operation) {
            $operations[(string) $method] = [$operation, [...$segments, 'additionalOperations', (string) $method]];
        }

        foreach ($operations as $method => [$operation, $at]) {
            if (! is_array($operation)) {
                continue;
            }

            /** @var array<string, mixed> $operation */
            $key = strtoupper($method).' '.($member === 'webhooks' ? 'webhook:'.$name : $this->identities->normalizePathTemplate($name));

            $this->nodes[] = new DocumentNode(
                NodeKind::Operation,
                NodeIdentity::inArray($operation),
                $key,
                Pointer::of($at),
                self::string($operation, 'summary') ?? self::string($operation, 'operationId'),
                ($operation['deprecated'] ?? null) === true,
                $this->digest(['paths' => ['/' => ['get' => $operation]]], ['paths', '/', 'get']),
            );

            $own = $this->parameters($operation['parameters'] ?? null, [...$at, 'parameters']);
            foreach ($own + $shared as $location => $parameter) {
                $this->parameter($key.' '.$location, ...$parameter);
            }

            foreach (self::map($operation['responses'] ?? null) as $status => $response) {
                $this->response($key.' '.$status, $response, [...$at, 'responses', (string) $status]);
            }
        }
    }

    /**
     * $parameters keyed by `in:name`, each with the parameter and its own segments. A `$ref` is keyed by
     * what it resolves to, and by the reference itself where that is nothing. A second parameter under one
     * key is a document OpenAPI calls invalid; the first is the one listed.
     *
     * @param  list<string>  $segments  where the list is written
     * @return array<string, array{mixed, list<string>}>
     */
    private function parameters(mixed $parameters, array $segments): array
    {
        $keyed = [];

        foreach (is_array($parameters) ? $parameters : [] as $index => $parameter) {
            $resolved = $this->resolved($parameter);
            $location = $resolved === null && is_array($parameter) && is_string($parameter['$ref'] ?? null)
                ? '$ref:'.$parameter['$ref']
                : (self::string($resolved ?? [], 'in') ?? '').':'.(self::string($resolved ?? [], 'name') ?? '');

            $keyed[$location] ??= [$parameter, [...$segments, (string) $index]];
        }

        return $keyed;
    }

    /**
     * @param  list<string>  $segments
     */
    private function parameter(string $key, mixed $parameter, array $segments): void
    {
        $resolved = $this->resolved($parameter) ?? [];

        $this->nodes[] = new DocumentNode(
            NodeKind::Parameter,
            $this->identity($parameter, $resolved),
            $key,
            Pointer::of($segments),
            self::string($resolved, 'name'),
            ($resolved['deprecated'] ?? null) === true,
            $this->digest(['paths' => ['/' => ['get' => ['parameters' => [$parameter]]]]], ['paths', '/', 'get', 'parameters', 0]),
        );
    }

    /**
     * @param  list<string>  $segments
     */
    private function response(string $key, mixed $response, array $segments): void
    {
        $resolved = $this->resolved($response) ?? [];
        $status = (string) end($segments);

        $this->nodes[] = new DocumentNode(
            NodeKind::Response,
            $this->identity($response, $resolved),
            $key,
            Pointer::of($segments),
            self::string($resolved, 'description'),
            false,
            $this->digest(['paths' => ['/' => ['get' => ['responses' => [$status => $response]]]]], ['paths', '/', 'get', 'responses', $status]),
        );
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
