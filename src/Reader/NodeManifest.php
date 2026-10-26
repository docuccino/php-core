<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Contract\RefHops;
use Docuccino\Core\Diff\PathOperations;
use Docuccino\Core\Document\NodeIdentity;
use Docuccino\Core\Document\UseSites;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\CompactJson;

/**
 * The {@see DocumentNode}s of a document. Read off the LOWERED document ({@see UseSites}), so a use's
 * id is found beside its `$ref` whichever UIR minor wrote it, and lowering moves nothing in a list, so
 * every pointer is still a position in the bytes that were read.
 *
 * An operation's key opens with where it is written below its path item, spelt as the differ spells it
 * ({@see PathOperations}): `GET` for the fixed `get`, `additionalOperations/get` for a custom `get`. So no
 * two operations of one path item share a key, and a custom method never takes a fixed one's place.
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
 * Both of those list one written node many times over, and every node's pointer and key spell again
 * the names written above it, so the index can outgrow the document by any factor, in nodes or in the
 * text they spell: {@see admit()} is the bound, counted before anything is spelt or digested. Within
 * it, a path item is read, a node digested and a reference followed ({@see RefHops}) once, however often
 * each is listed.
 *
 * @internal
 *
 * @phpstan-type Listed array{node: mixed, tail: string, label: ?string, identity: ?string, spelled: int}
 * @phpstan-type Parameter array{node: mixed, tail: string, label: ?string, identity: ?string, spelled: int, in: string, name: string, deprecated: bool}
 * @phpstan-type Planned array{written: string, operation: array<string, mixed>, tail: string, label: ?string, identity: ?string, parameters: list<Parameter>, restated: array<int, true>, responses: array<array-key, Listed>}
 * @phpstan-type Plan array{shared: list<Parameter>, operations: list<Planned>, listed: int, spelled: int}
 * @phpstan-type Component array{NodeKind, ?string, string, string, ?string, bool, array<string, mixed>, list<string|int>}
 */
final class NodeManifest
{
    /** The fewest nodes a document may index to, so a small one sharing a path item is never refused. */
    private const int MIN_NODES = 10_000;

    /**
     * The most a document's compact JSON counts for, as a multiple of the text it was read from. Only YAML
     * aliases write a document that much larger than its text: without them a list of floats like `1e14`
     * grows the most, 3.2 times, and an escape like `"\0"` 3.
     */
    private const int MAX_GROWTH = 4;

    /**
     * The bytes the index may spell — every node's pointer, key, label and id — for each byte the document
     * takes as compact JSON. The most any real description measured spells is 1.4, and 2.0 counted against
     * its paths alone, as a description whose components live in another file would be.
     */
    private const int TEXT_PER_BYTE = 16;

    /**
     * The least text an index may spell, so a small document indexing to {@see MIN_NODES} is never refused:
     * 419 bytes a node, where the most any real description spells is 231.
     */
    private const int MIN_TEXT = 4_194_304;

    private readonly Canonicalizer $canonicalizer;

    private readonly CanonicalJsonSerializer $serializer;

    private readonly UirEmitter $emitter;

    private readonly IdentityGenerator $identities;

    /** @var array<string, mixed> */
    private array $document = [];

    /** The document's references, each followed once. */
    private RefHops $refs;

    /** @var list<DocumentNode> */
    private array $nodes = [];

    /**
     * Each path item's operations, by where the item is written.
     *
     * @var array<string, Plan>
     */
    private array $plans = [];

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
        $this->refs = new RefHops([]);
    }

    /**
     * Refuses $document where it would index to more nodes than it takes bytes as compact JSON
     * ({@see CompactJson}), or than {@see MIN_NODES} for a small one. A document listing every node where
     * it is written cannot spell more, so past that the index is only repeating what the document shares.
     *
     * Refuses it too where those nodes would spell more than {@see TEXT_PER_BYTE} bytes a byte, or than
     * {@see MIN_TEXT} for a small one, in their pointers, keys, labels and ids: a pointer and a key spell
     * every name above the node, so one long name over many nodes costs their product with nothing shared
     * at all, and a shared node's label is spelt again wherever it is listed.
     *
     * The size is the document's, not its text's: whitespace, comments and a YAML layout neither raise
     * the limit nor lower it, so {@see ReadDocument::json()} reads back under the limit the document was
     * read under. The exception is a text the document outgrows more than {@see MAX_GROWTH} times, which
     * only aliases write; there the limit is that multiple of the text, so an alias cannot buy an index.
     *
     * @param  array<string, mixed>  $document  as read, in either UIR minor's form
     * @param  int  $text  the length of the text it was read from
     *
     * @throws UnreadableDocumentException
     */
    public function admit(array $document, int $text): void
    {
        // The most the document can count for, so the most either limit can be: the text is counted no further.
        $most = self::MAX_GROWTH * $text;
        [$count, $spelled] = $this->tally($document, max(self::MIN_TEXT, self::TEXT_PER_BYTE * $most));

        if ($count <= self::MIN_NODES && $spelled <= self::MIN_TEXT) {
            return;
        }

        // Counted only as far as the answer needs: a document far under its limits measures a sliver of itself.
        $size = CompactJson::lengthUpTo($document, min(max($count, intdiv($spelled + self::TEXT_PER_BYTE - 1, self::TEXT_PER_BYTE)), $most));

        if ($count > self::MIN_NODES && $size < $count) {
            throw new UnreadableDocumentException(ReadFailure::NodeExpansion, sprintf(
                'The document expands to %d nodes, more than the %d its size allows: the path items its paths share, and the parameters a path item lists for every operation, repeat far more often than any document needs, so it was refused rather than indexed.',
                $count,
                max(self::MIN_NODES, $size),
            ));
        }

        $limit = max(self::MIN_TEXT, self::TEXT_PER_BYTE * $size);

        if ($spelled > $limit) {
            throw new UnreadableDocumentException(ReadFailure::NodeExpansion, sprintf(
                'The document\'s node index spells more than the %d bytes its size allows: every node\'s pointer and key repeat the names written above it, and a node its paths or operations share is spelt again at each, far more often than any document needs, so it was refused rather than indexed.',
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
        return $this->tally($document, PHP_INT_MAX - 1)[0];
    }

    /**
     * How many bytes the pointers, keys, labels and ids of {@see of()}'s nodes spell for $document,
     * counted without spelling any.
     *
     * @param  array<string, mixed>  $document  as read, in either UIR minor's form
     */
    public function text(array $document): int
    {
        return $this->tally($document, PHP_INT_MAX - 1)[1];
    }

    /**
     * @param  array<string, mixed>  $document  as read, in either UIR minor's form
     * @return list<DocumentNode>
     */
    public function of(array $document): array
    {
        $this->start($document);

        foreach ($this->pathItems() as [$member, $name, $item, $at]) {
            $path = $this->path($member, $name);

            $plan = $this->plan($item, $at);
            foreach ($plan['operations'] as $planned) {
                $this->operation($planned['written'].' '.$path, $at, $planned, $plan['shared']);
            }
        }

        foreach ($this->components() as [$kind, $identity, $key, $pointer, $label, $deprecated, $holder, $segments]) {
            $this->nodes[] = new DocumentNode($kind, $identity, $key, $pointer, $label, $deprecated, $this->digest($holder, $segments));
        }

        $rank = static fn (DocumentNode $node): int => (int) array_search($node->kind, NodeKind::cases(), true);
        usort($this->nodes, static fn (DocumentNode $a, DocumentNode $b): int => [$rank($a), $a->key, $a->pointer] <=> [$rank($b), $b->key, $b->pointer]);

        return $this->nodes;
    }

    /**
     * How many nodes {@see of()} lists for $document, and how many bytes their pointers, keys, labels and
     * ids spell — the second counted no further than past $enough. Nothing is listed or spelt: a path's
     * share is its path item's, plus its own key and the item's pointer once for each node listed.
     *
     * @param  array<string, mixed>  $document
     * @return array{int, int}
     */
    private function tally(array $document, int $enough): array
    {
        $this->start($document);

        $count = 0;
        $spelled = 0;
        foreach ($this->pathItems() as [$member, $name, $item, $at]) {
            $plan = $this->plan($item, $at);

            $count += $plan['listed'];
            if ($spelled <= $enough) {
                $spelled = self::plus(self::plus($spelled, 1, $plan['spelled'], $enough), $plan['listed'], strlen($at) + strlen($this->path($member, $name)), $enough);
            }
        }

        foreach ($this->components() as [, $identity, $key, $pointer, $label]) {
            $count++;
            $spelled = self::plus($spelled, 1, strlen($identity ?? '') + strlen($key) + strlen($pointer) + strlen($label ?? ''), $enough);
        }

        return [$count, $spelled];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function start(array $document): void
    {
        $this->document = UseSites::lower($document);
        $this->refs = new RefHops($this->document);
        $this->nodes = [];
        $this->plans = [];
        $this->digests = [];
    }

    /**
     * Every path and webhook, with the path item it lists operations from and the pointer to where that
     * item is written — which, for a path written as a `$ref`, is where the reference lands, spelt once
     * however many paths reference it. One landing nowhere lists nothing.
     *
     * @return iterable<array{string, string, array<string, mixed>, string}>
     */
    private function pathItems(): iterable
    {
        foreach (['paths', 'webhooks'] as $member) {
            foreach (self::map($this->document[$member] ?? null) as $name => $item) {
                if (! is_array($item)) {
                    continue;
                }

                /** @var array<string, mixed> $item */
                $at = Pointer::of([$member, (string) $name]);

                if (is_string($item['$ref'] ?? null)) {
                    [$item, $at, $dangling] = $this->refs->followTo($item, $at);
                    if ($dangling !== null) {
                        continue;
                    }
                }

                yield [$member, (string) $name, $item, $at];
            }
        }
    }

    /** What an operation's key names its path by: the template with its parameters numbered, or the webhook. */
    private function path(string $member, string $name): string
    {
        return $member === 'webhooks' ? 'webhook:'.$name : $this->identities->normalizePathTemplate($name);
    }

    /**
     * The parameters the path item written at $at shares, and its operations, each with its own parameters
     * and its responses — read once, however many paths reference the item. The two lists of parameters
     * are kept apart: merged, a path item with many operations and many parameters would hold their product.
     *
     * Nothing that repeats what is above a node is spelt here. A node holds where it is written relative
     * to its operation (or, for a shared parameter, its path item), and the plan holds how many nodes it
     * lists and how many bytes they spell besides the path's key and the item's pointer, which every
     * listing repeats and {@see tally()} adds once per path.
     *
     * @param  array<string, mixed>  $item
     * @return Plan
     */
    private function plan(array $item, string $at): array
    {
        if (isset($this->plans[$at])) {
            return $this->plans[$at];
        }

        [$shared, $sharing] = $this->parameters($item['parameters'] ?? null);
        $everyShared = self::spelt($shared);

        $plan = ['shared' => $shared, 'operations' => [], 'listed' => 0, 'spelled' => 0];
        foreach (PathOperations::written($item) as [$written, $tail, $operation]) {
            /** @var array<string, mixed> $operation */
            [$parameters] = $this->parameters($operation['parameters'] ?? null);

            // The shared parameters this operation states again, which it lists from its own instead.
            $restated = [];
            $sharedSpelled = $everyShared;
            foreach ($parameters as $parameter) {
                $index = $sharing[$parameter['in']][$parameter['name']] ?? null;
                if ($index !== null) {
                    $restated[$index] = true;
                    $sharedSpelled -= $shared[$index]['spelled'];
                }
            }

            $responses = [];
            foreach (self::map($operation['responses'] ?? null) as $status => $response) {
                $resolved = $this->resolved($response) ?? [];
                $responses[$status] = self::listed(
                    $response,
                    '/responses/'.Pointer::escape((string) $status),
                    self::string($resolved, 'description'),
                    $this->identity($response, $resolved),
                    1 + strlen((string) $status),
                );
            }

            $label = self::string($operation, 'summary') ?? self::string($operation, 'operationId');
            $identity = NodeIdentity::inArray($operation);
            $listed = 1 + count($parameters) + count($shared) - count($restated) + count($responses);

            $plan['operations'][] = [
                'written' => $written,
                'operation' => $operation,
                'tail' => $tail,
                'label' => $label,
                'identity' => $identity,
                'parameters' => $parameters,
                'restated' => $restated,
                'responses' => $responses,
            ];

            // Each listed node's key opens with where the operation is written; its own nodes point below its tail.
            $plan['listed'] += $listed;
            $plan['spelled'] += strlen($label ?? '') + strlen($identity ?? '')
                + $listed * (strlen($written) + 1)
                + (1 + count($parameters) + count($responses)) * strlen($tail)
                + self::spelt($parameters) + $sharedSpelled + self::spelt($responses);
        }

        return $this->plans[$at] = $plan;
    }

    /**
     * An operation's node under $key, then its parameters' — its own, and those its path item shares
     * that it does not restate — and its responses'.
     *
     * @param  Planned  $planned
     * @param  list<Parameter>  $shared
     */
    private function operation(string $key, string $at, array $planned, array $shared): void
    {
        $pointer = $at.$planned['tail'];

        $this->nodes[] = new DocumentNode(
            NodeKind::Operation,
            $planned['identity'],
            $key,
            $pointer,
            $planned['label'],
            ($planned['operation']['deprecated'] ?? null) === true,
            $this->digests[$pointer] ??= $this->digest(['paths' => ['/' => ['get' => $planned['operation']]]], ['paths', '/', 'get']),
        );

        $parameters = array_map(static fn (array $parameter): array => [$parameter, $pointer.$parameter['tail']], $planned['parameters']);
        foreach ($shared as $index => $parameter) {
            if (! isset($planned['restated'][$index])) {
                $parameters[] = [$parameter, $at.$parameter['tail']];
            }
        }

        foreach ($parameters as [$parameter, $at]) {
            $this->nodes[] = new DocumentNode(
                NodeKind::Parameter,
                $parameter['identity'],
                $key.' '.$parameter['in'].':'.$parameter['name'],
                $at,
                $parameter['label'],
                $parameter['deprecated'],
                $this->digests[$at] ??= $this->digest(['paths' => ['/' => ['get' => ['parameters' => [$parameter['node']]]]]], ['paths', '/', 'get', 'parameters', 0]),
            );
        }

        foreach ($planned['responses'] as $status => $response) {
            $at = $pointer.$response['tail'];

            $this->nodes[] = new DocumentNode(
                NodeKind::Response,
                $response['identity'],
                $key.' '.$status,
                $at,
                $response['label'],
                false,
                $this->digests[$at] ??= $this->digest(['paths' => ['/' => ['get' => ['responses' => [$status => $response['node']]]]]], ['paths', '/', 'get', 'responses', (string) $status]),
            );
        }
    }

    /**
     * $parameters, each keyed by the `in` and `name` it resolves to — or, for a `$ref` resolving to
     * nothing, by the reference — and where it is written below the list's owner. A second parameter under
     * one `in` and `name` is a document OpenAPI calls invalid; the first is the one listed. Keyed by `in`
     * then `name` rather than by the two spelt together, so a shared parameter's name is never copied
     * once for each use.
     *
     * @return array{list<Parameter>, array<array-key, array<array-key, int>>} the parameters, and each one's position among them by `in` then `name`
     */
    private function parameters(mixed $parameters): array
    {
        $listed = [];
        $positions = [];

        foreach (is_array($parameters) ? $parameters : [] as $index => $parameter) {
            $resolved = $this->resolved($parameter);
            [$in, $name] = $resolved === null && is_array($parameter) && is_string($parameter['$ref'] ?? null)
                ? ['$ref', $parameter['$ref']]
                : [self::string($resolved ?? [], 'in') ?? '', self::string($resolved ?? [], 'name') ?? ''];

            if (isset($positions[$in][$name])) {
                continue;
            }

            $positions[$in][$name] = count($listed);
            $listed[] = [
                ...self::listed(
                    $parameter,
                    '/parameters/'.Pointer::escape((string) $index),
                    self::string($resolved ?? [], 'name'),
                    $this->identity($parameter, $resolved ?? []),
                    2 + strlen($in) + strlen($name),
                ),
                'in' => $in,
                'name' => $name,
                'deprecated' => ($resolved['deprecated'] ?? null) === true,
            ];
        }

        return [$listed, $positions];
    }

    /**
     * A node of an operation, with what its listing spells besides the path's key, the item's pointer and
     * the method: its own pointer below them, its label and id, and the $key bytes its key adds.
     *
     * @return Listed
     */
    private static function listed(mixed $node, string $tail, ?string $label, ?string $identity, int $key): array
    {
        return [
            'node' => $node,
            'tail' => $tail,
            'label' => $label,
            'identity' => $identity,
            'spelled' => strlen($tail) + strlen($label ?? '') + strlen($identity ?? '') + $key,
        ];
    }

    /**
     * What every one of $listed spells, together.
     *
     * @param  array<array-key, Listed|Parameter>  $listed
     */
    private static function spelt(array $listed): int
    {
        $spelt = 0;
        foreach ($listed as $node) {
            $spelt += $node['spelled'];
        }

        return $spelt;
    }

    /**
     * Every node listed once, where it is written: component schemas, shared parameters and responses —
     * each a node of its own, keyed by the reference its uses write, so an edit to it moves its digest even
     * though every use's digest is only of that reference — pages and workflows. Each with what to digest:
     * the node in a document holding nothing else, and where it is in that document.
     *
     * @return iterable<Component>
     */
    private function components(): iterable
    {
        $components = self::map($this->document['components'] ?? null);
        foreach (self::map($components['schemas'] ?? null) as $name => $schema) {
            $name = (string) $name;
            $segments = ['components', 'schemas', $name];

            yield [
                NodeKind::Schema,
                $this->identity($schema, []),
                $name,
                Pointer::of($segments),
                is_array($schema) ? self::string($schema, 'title') : null,
                is_array($schema) && ($schema['deprecated'] ?? null) === true,
                ['components' => ['schemas' => [$name => $schema]]],
                $segments,
            ];
        }

        foreach (['parameters' => [NodeKind::Parameter, 'name'], 'responses' => [NodeKind::Response, 'description']] as $member => [$kind, $label]) {
            foreach (self::map($components[$member] ?? null) as $name => $node) {
                $segments = ['components', $member, (string) $name];
                $resolved = $this->resolved($node) ?? [];

                yield [
                    $kind,
                    $this->identity($node, $resolved),
                    '#'.Pointer::of($segments),
                    Pointer::of($segments),
                    self::string($resolved, $label),
                    $kind === NodeKind::Parameter && ($resolved['deprecated'] ?? null) === true,
                    ['components' => [$member => [(string) $name => $node]]],
                    $segments,
                ];
            }
        }

        $extension = self::map($this->document['x-docuccino'] ?? null);
        foreach (self::map(self::map($extension['content'] ?? null)['pages'] ?? null) as $index => $page) {
            if (! is_array($page)) {
                continue;
            }

            /** @var array<string, mixed> $page */
            yield [
                NodeKind::Page,
                self::string($page, 'id'),
                self::string($page, 'slug') ?? '',
                Pointer::of(['x-docuccino', 'content', 'pages', (string) $index]),
                self::string($page, 'title'),
                false,
                ['x-docuccino' => ['content' => ['pages' => [$page]]]],
                ['x-docuccino', 'content', 'pages', 0],
            ];
        }

        foreach (self::map($extension['workflows'] ?? null) as $index => $workflow) {
            if (! is_array($workflow)) {
                continue;
            }

            /** @var array<string, mixed> $workflow */
            yield [
                NodeKind::Workflow,
                null,
                self::string($workflow, 'id') ?? '',
                Pointer::of(['x-docuccino', 'workflows', (string) $index]),
                self::string($workflow, 'summary'),
                false,
                ['x-docuccino' => ['workflows' => [$workflow]]],
                ['x-docuccino', 'workflows', 0],
            ];
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
        [$resolved, , $dangling] = $this->refs->follow($node, []);

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
     * $total plus $times lots of $each, or one past $enough where that passes it: an index past every
     * limit is refused however far past, so the count need not follow it there.
     */
    private static function plus(int $total, int $times, int $each, int $enough): int
    {
        if ($total > $enough || ($each > 0 && $times > intdiv($enough - $total, $each))) {
            return $enough + 1;
        }

        return $total + $times * $each;
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
