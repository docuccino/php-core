<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\Content\Page;
use Docuccino\Core\Document\NodeIdentity;
use Docuccino\Core\Document\Operation;
use Docuccino\Core\Document\Parameter;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Json;

/**
 * The semantic diff engine: compares two {@see UirDocument}s by their stable `x-docuccino.id`s,
 * so a path-parameter rename (same op id) reads as no change while a URI change reads as
 * remove + add. Walks operations, their parameters/responses/request bodies, named component
 * schemas and content pages, delegating field-level schema comparison to
 * {@see SchemaComparator}, and flags each {@see Change} breaking or not.
 *
 * Responses and parameters are read through {@see ComponentRefs} on both sides, so where one lives —
 * inline or hoisted into `components` — is not itself a change, while a shared one's content is compared
 * under every operation that `$ref`s it. An operation's parameters are its own plus the ones its path item
 * declares for every operation under it, minus any the operation restates for the same `in` + `name`.
 *
 * Breaking: a removed operation/parameter/response/status, a parameter becoming required, an
 * added required parameter, plus the schema-level rules SchemaComparator owns. Additions, prose
 * edits and deprecations aren't — and since only modelled fields and schema structure are
 * compared, a provenance-only delta yields an empty changeset.
 *
 * Identities carry an algorithm version (`op:v1:…`); documents minted by different algo versions
 * can't be paired safely, so the differ throws {@see IncomparableDocumentsException} rather than
 * mis-report.
 *
 * Identity pairing needs identities on BOTH sides. An artifact exported with ids dropped, or a spec from
 * another tool, has none, so the differ falls back to method + path on both sides and reports which it
 * used ({@see Pairing}) — see {@see pairing()} for why the one-sided case can't be paired by id at all.
 * Every node's id is read through {@see NodeIdentity}, which knows both forms an artifact can carry, and
 * keyed through {@see IdentityKeys}, which owns what happens when two nodes claim one key.
 *
 * @phpstan-type OperationEntry array{path: string, method: string, op: Operation, shared: list<Parameter>}
 */
final class DocumentDiffer
{
    public function __construct(
        private readonly SchemaComparator $schemas = new SchemaComparator,
    ) {}

    public function diff(UirDocument $old, UirDocument $new): Changeset
    {
        $pairing = $this->pairing($old, $new);

        /** @var list<Change> $changes */
        $changes = [];

        $this->diffOperations($old, $new, $changes, $pairing);
        $this->diffComponentSchemas($old, $new, $changes, $pairing);
        $this->diffPages($old, $new, $changes);

        usort($changes, static fn (Change $a, Change $b): int => $a->sortKey() <=> $b->sortKey());

        $disjoint = $pairing === Pairing::Identity ? IdentityOverlap::disjointKinds($old, $new) : [];

        return new Changeset($changes, $pairing, $disjoint);
    }

    /**
     * One side carrying identities and the other not is the shape an artifact exported with `--drop-ids`
     * makes (and every artifact written before ids were kept by default). Pairing it against a freshly
     * built document by id would put the two sides in disjoint key spaces, reporting every operation
     * removed AND re-added. Falling back to method + path on both sides is what every other OpenAPI
     * differ does: weaker (a rename reads as remove + add) but never phantom.
     */
    private function pairing(UirDocument $old, UirDocument $new): Pairing
    {
        $oldAlgo = $this->firstAlgoVersion($old);
        $newAlgo = $this->firstAlgoVersion($new);

        if ($oldAlgo === null || $newAlgo === null) {
            return Pairing::Structural;
        }

        if ($oldAlgo !== $newAlgo) {
            throw IncomparableDocumentsException::algoMismatch($oldAlgo, $newAlgo);
        }

        return Pairing::Identity;
    }

    private function firstAlgoVersion(UirDocument $document): ?string
    {
        foreach ($this->operations($document) as [$op]) {
            $algo = self::algoVersionOf(self::operationId($op));
            if ($algo !== null) {
                return $algo;
            }
        }

        $components = $document->components;
        if ($components !== null) {
            foreach ($components->schemas as $schema) {
                $algo = self::algoVersionOf(self::schemaId($schema->toArray()));
                if ($algo !== null) {
                    return $algo;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<Change>  $changes
     */
    private function diffOperations(UirDocument $old, UirDocument $new, array &$changes, Pairing $pairing): void
    {
        [$oldOps, $newOps] = IdentityKeys::pair(
            $this->operationEntries($old, $pairing),
            $this->operationEntries($new, $pairing),
        );
        $oldRefs = ComponentRefs::of($old);
        $newRefs = ComponentRefs::of($new);

        foreach (Arr::sortedUnion(array_keys($oldOps), array_keys($newOps)) as $key) {
            $inOld = array_key_exists($key, $oldOps);
            $inNew = array_key_exists($key, $newOps);

            if (! $inNew) {
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Operation, $key, $this->display($oldOps[$key]), true, 'operation.removed');
            } elseif (! $inOld) {
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Operation, $key, $this->display($newOps[$key]), false, 'operation.added');
            } else {
                $this->diffOperationPair($key, $oldOps[$key], $newOps[$key], $oldRefs, $newRefs, $changes, $pairing);
            }
        }
    }

    /**
     * @param  OperationEntry  $old
     * @param  OperationEntry  $new
     * @param  list<Change>  $changes
     */
    private function diffOperationPair(string $id, array $old, array $new, ComponentRefs $oldRefs, ComponentRefs $newRefs, array &$changes, Pairing $pairing): void
    {
        $path = $this->display($new);
        $oldOp = $old['op'];
        $newOp = $new['op'];

        $this->scalarChange($changes, ChangeTarget::Operation, $id, $path, 'operation.operationId-changed', 'operationId', $oldOp->operationId, $newOp->operationId);
        $this->scalarChange($changes, ChangeTarget::Operation, $id, $path, 'operation.summary-changed', 'summary', $oldOp->summary, $newOp->summary);
        $this->scalarChange($changes, ChangeTarget::Operation, $id, $path, 'operation.description-changed', 'description', $oldOp->description, $newOp->description);
        $this->scalarChange($changes, ChangeTarget::Operation, $id, $path, 'operation.deprecated-changed', 'deprecated', $oldOp->deprecated, $newOp->deprecated);

        if ($oldOp->tags !== $newOp->tags) {
            $changes[] = new Change(ChangeKind::Changed, ChangeTarget::Operation, $id, $path, false, 'operation.tags-changed', [new FieldChange('tags', $oldOp->tags, $newOp->tags)]);
        }

        $this->diffSecurity($id, $path, $oldOp, $newOp, $changes);
        $this->diffParameters($id, $path, $old, $new, $oldRefs, $newRefs, $changes, $pairing);
        $this->diffResponses($id, $path, $oldOp, $newOp, $oldRefs, $newRefs, $changes);
        $this->diffRequestBody($id, $path, $oldOp, $newOp, $changes);
    }

    /**
     * Adding a `security` requirement forces clients to authenticate where they didn't before
     * (breaking); removing one relaxes access. Requirements compare whole, by canonical JSON, so
     * a reordered scheme map isn't a change.
     *
     * @param  list<Change>  $changes
     */
    private function diffSecurity(string $opId, string $path, Operation $old, Operation $new, array &$changes): void
    {
        $oldReqs = self::securityRequirementKeys($old);
        $newReqs = self::securityRequirementKeys($new);

        foreach (array_keys($newReqs) as $key) {
            if (! array_key_exists($key, $oldReqs)) {
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Operation, $opId, $path.' security', true, 'operation.security-added', [new FieldChange('security', null, $newReqs[$key])]);
            }
        }

        foreach (array_keys($oldReqs) as $key) {
            if (! array_key_exists($key, $newReqs)) {
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Operation, $opId, $path.' security', false, 'operation.security-removed', [new FieldChange('security', $oldReqs[$key], null)]);
            }
        }
    }

    /**
     * Canonical key → requirement, so added/removed are set differences insensitive to ordering.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function securityRequirementKeys(Operation $op): array
    {
        $out = [];

        foreach ($op->security ?? [] as $requirement) {
            $canonical = $requirement;
            ksort($canonical);
            $key = json_encode($canonical);
            $out[is_string($key) ? $key : ''] = $requirement;
        }

        return $out;
    }

    /**
     * @param  OperationEntry  $old
     * @param  OperationEntry  $new
     * @param  list<Change>  $changes
     */
    private function diffParameters(string $opId, string $path, array $old, array $new, ComponentRefs $oldRefs, ComponentRefs $newRefs, array &$changes, Pairing $pairing): void
    {
        [$oldParams, $newParams] = IdentityKeys::pair(
            $this->parameterEntries($old, $oldRefs, $pairing),
            $this->parameterEntries($new, $newRefs, $pairing),
        );

        foreach (Arr::sortedUnion(array_keys($oldParams), array_keys($newParams)) as $key) {
            $inOld = array_key_exists($key, $oldParams);
            $inNew = array_key_exists($key, $newParams);

            if (! $inNew) {
                $param = $oldParams[$key];
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Parameter, self::nodeId(self::parameterId($param), $opId, $key), $path.' parameters '.self::paramLabel($param), true, 'parameter.removed');
            } elseif (! $inOld) {
                $param = $newParams[$key];
                $breaking = $param->required === true;
                $code = $breaking ? 'parameter.added-required' : 'parameter.added';
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Parameter, self::nodeId(self::parameterId($param), $opId, $key), $path.' parameters '.self::paramLabel($param), $breaking, $code);
            } else {
                $this->diffParameterPair($opId, $path, $key, $oldParams[$key], $newParams[$key], $changes);
            }
        }
    }

    /**
     * @param  list<Change>  $changes
     */
    private function diffParameterPair(string $opId, string $path, string $key, Parameter $old, Parameter $new, array &$changes): void
    {
        $id = self::nodeId(self::parameterId($new), $opId, $key);
        $paramPath = $path.' parameters '.self::paramLabel($new);

        if ($old->required !== true && $new->required === true) {
            $changes[] = new Change(ChangeKind::Changed, ChangeTarget::Parameter, $id, $paramPath, true, 'parameter.became-required', [new FieldChange('required', $old->required, $new->required)]);
        } elseif ($old->required === true && $new->required !== true) {
            $changes[] = new Change(ChangeKind::Changed, ChangeTarget::Parameter, $id, $paramPath, false, 'parameter.became-optional', [new FieldChange('required', $old->required, $new->required)]);
        }

        $this->scalarChange($changes, ChangeTarget::Parameter, $id, $paramPath, 'parameter.description-changed', 'description', $old->description, $new->description);

        $oldSchema = $old->schema?->toArray() ?? [];
        $newSchema = $new->schema?->toArray() ?? [];

        foreach ($this->schemas->compare($oldSchema, $newSchema, $paramPath.' schema', $id, request: true) as $change) {
            $changes[] = $change;
        }
    }

    /**
     * @param  list<Change>  $changes
     */
    private function diffResponses(string $opId, string $path, Operation $old, Operation $new, ComponentRefs $oldRefs, ComponentRefs $newRefs, array &$changes): void
    {
        $oldResponses = array_map($oldRefs->resolveResponse(...), $old->responses);
        $newResponses = array_map($newRefs->resolveResponse(...), $new->responses);

        foreach (Arr::sortedUnion(array_keys($oldResponses), array_keys($newResponses)) as $status) {
            $inOld = array_key_exists($status, $oldResponses);
            $inNew = array_key_exists($status, $newResponses);

            if (! $inNew) {
                $response = $oldResponses[$status];
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Response, self::nodeId(self::responseId($response), $opId, $status), $path.' responses '.$status, true, 'response.removed');
            } elseif (! $inOld) {
                $response = $newResponses[$status];
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Response, self::nodeId(self::responseId($response), $opId, $status), $path.' responses '.$status, false, 'response.added');
            } else {
                $this->diffResponsePair($opId, $path, $status, $oldResponses[$status], $newResponses[$status], $changes);
            }
        }
    }

    /**
     * @param  list<Change>  $changes
     */
    private function diffResponsePair(string $opId, string $path, string $status, ResponseObject $old, ResponseObject $new, array &$changes): void
    {
        $id = self::nodeId(self::responseId($new), $opId, $status);
        $responsePath = $path.' responses '.$status;

        $this->scalarChange($changes, ChangeTarget::Response, $id, $responsePath, 'response.description-changed', 'description', $old->description, $new->description);

        $oldContent = self::contentSchemas($old->content);
        $newContent = self::contentSchemas($new->content);

        foreach (Arr::sortedUnion(array_keys($oldContent), array_keys($newContent)) as $media) {
            $mediaPath = $responsePath.' '.$media;
            $inOld = array_key_exists($media, $oldContent);
            $inNew = array_key_exists($media, $newContent);

            if (! $inNew) {
                // Dropping a media type a response used to offer breaks consumers negotiating it.
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Response, $id, $mediaPath, true, 'response.content-removed');
            } elseif (! $inOld) {
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Response, $id, $mediaPath, false, 'response.content-added');
            } else {
                foreach ($this->schemas->compare($oldContent[$media], $newContent[$media], $mediaPath.' schema', $id, request: false) as $change) {
                    $changes[] = $change;
                }
            }
        }
    }

    /**
     * @param  list<Change>  $changes
     */
    private function diffRequestBody(string $opId, string $path, Operation $old, Operation $new, array &$changes): void
    {
        $oldContent = self::requestBodySchemas($old);
        $newContent = self::requestBodySchemas($new);

        foreach (Arr::sortedUnion(array_keys($oldContent), array_keys($newContent)) as $media) {
            if (! array_key_exists($media, $oldContent) || ! array_key_exists($media, $newContent)) {
                continue;
            }

            foreach ($this->schemas->compare($oldContent[$media], $newContent[$media], $path.' requestBody '.$media.' schema', $opId, request: true) as $change) {
                $changes[] = $change;
            }
        }
    }

    /**
     * @param  list<Change>  $changes
     */
    private function diffComponentSchemas(UirDocument $old, UirDocument $new, array &$changes, Pairing $pairing): void
    {
        [$oldSchemas, $newSchemas] = IdentityKeys::pair(
            $this->componentSchemaEntries($old, $pairing),
            $this->componentSchemaEntries($new, $pairing),
        );

        foreach (Arr::sortedUnion(array_keys($oldSchemas), array_keys($newSchemas)) as $key) {
            $inOld = array_key_exists($key, $oldSchemas);
            $inNew = array_key_exists($key, $newSchemas);

            if (! $inNew) {
                $entry = $oldSchemas[$key];
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Schema, $key, 'components.schemas.'.$entry['name'], false, 'schema.removed');
            } elseif (! $inOld) {
                $entry = $newSchemas[$key];
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Schema, $key, 'components.schemas.'.$entry['name'], false, 'schema.added');
            } else {
                $entry = $newSchemas[$key];
                foreach ($this->schemas->compare($oldSchemas[$key]['schema'], $entry['schema'], 'components.schemas.'.$entry['name'], $key, request: false) as $change) {
                    $changes[] = $change;
                }
            }
        }
    }

    /**
     * @param  list<Change>  $changes
     */
    private function diffPages(UirDocument $old, UirDocument $new, array &$changes): void
    {
        [$oldPages, $newPages] = IdentityKeys::pair($this->pageEntries($old), $this->pageEntries($new));

        foreach (Arr::sortedUnion(array_keys($oldPages), array_keys($newPages)) as $key) {
            $inOld = array_key_exists($key, $oldPages);
            $inNew = array_key_exists($key, $newPages);

            if (! $inNew) {
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::ContentPage, $key, 'pages '.self::pageSlug($oldPages[$key]), false, 'page.removed');
            } elseif (! $inOld) {
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::ContentPage, $key, 'pages '.self::pageSlug($newPages[$key]), false, 'page.added');
            } else {
                $oldPage = $oldPages[$key];
                $newPage = $newPages[$key];
                $pagePath = 'pages '.self::pageSlug($newPage);
                $this->scalarChange($changes, ChangeTarget::ContentPage, $key, $pagePath, 'page.title-changed', 'title', $oldPage['title'] ?? null, $newPage['title'] ?? null);
                $this->scalarChange($changes, ChangeTarget::ContentPage, $key, $pagePath, 'page.content-changed', 'content', $oldPage['content'] ?? null, $newPage['content'] ?? null);
            }
        }
    }

    /**
     * @param  list<Change>  $changes
     */
    private function scalarChange(array &$changes, ChangeTarget $target, string $id, string $path, string $code, string $field, mixed $old, mixed $new): void
    {
        if ($old !== $new) {
            $changes[] = new Change(ChangeKind::Changed, $target, $id, $path, false, $code, [new FieldChange($field, $old, $new)]);
        }
    }

    /**
     * @return list<array{0: ?string, 1: string, 2: string, 3: OperationEntry}>
     */
    private function operationEntries(UirDocument $document, Pairing $pairing): array
    {
        $out = [];

        foreach ($this->operations($document) as [$op, $method, $path, $shared]) {
            $out[] = [
                $pairing === Pairing::Identity ? self::operationId($op) : null,
                '_'.strtoupper($method).' '.$path,
                self::fingerprint($op->toArray()),
                ['path' => $path, 'method' => $method, 'op' => $op, 'shared' => $shared],
            ];
        }

        return $out;
    }

    private static function operationId(Operation $operation): ?string
    {
        return NodeIdentity::of($operation->docuccino, $operation->rest);
    }

    /**
     * @return list<array{0: Operation, 1: string, 2: string, 3: list<Parameter>}>
     */
    private function operations(UirDocument $document): array
    {
        $out = [];

        foreach ($document->paths ?? [] as $template => $item) {
            foreach ($item->operations as $method => $op) {
                $out[] = [$op, $method, (string) $template, $item->parameters];
            }
        }

        return $out;
    }

    /**
     * The operation's own parameters plus the ones its path item declares for every operation under it.
     * Per OAS an operation's entry replaces the path item's for the same `in` + `name`, which is the pair
     * {@see paramLabel} names — so the two are one parameter and only the operation's is compared.
     *
     * @param  OperationEntry  $entry
     * @return list<array{0: ?string, 1: string, 2: string, 3: Parameter}>
     */
    private function parameterEntries(array $entry, ComponentRefs $refs, Pairing $pairing): array
    {
        $own = array_map($refs->resolveParameter(...), $entry['op']->parameters);

        $restated = [];
        foreach ($own as $param) {
            $restated[self::paramLabel($param)] = true;
        }

        $effective = [];
        foreach ($entry['shared'] as $param) {
            $param = $refs->resolveParameter($param);
            if (! isset($restated[self::paramLabel($param)])) {
                $effective[] = $param;
            }
        }

        $out = [];
        foreach ([...$effective, ...$own] as $param) {
            $out[] = [
                $pairing === Pairing::Identity ? self::parameterId($param) : null,
                self::paramLabel($param),
                self::fingerprint($param->toArray()),
                $param,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{0: ?string, 1: string, 2: string, 3: array{name: string, schema: array<string, mixed>}}>
     */
    private function componentSchemaEntries(UirDocument $document, Pairing $pairing): array
    {
        $out = [];

        $components = $document->components;
        if ($components === null) {
            return $out;
        }

        foreach ($components->schemas as $name => $schema) {
            $data = $schema->toArray();
            $out[] = [
                $pairing === Pairing::Identity ? self::schemaId($data) : null,
                'name:'.$name,
                self::fingerprint($data),
                ['name' => (string) $name, 'schema' => $data],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{0: ?string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    private function pageEntries(UirDocument $document): array
    {
        $out = [];

        $content = $document->docuccino?->content;
        if ($content === null) {
            return $out;
        }

        foreach ($content->pages as $page) {
            $data = $page->toArray();
            $out[] = [$page->id !== '' ? $page->id : null, self::pageKey($page, $data), self::fingerprint($data), $data];
        }

        return $out;
    }

    /**
     * A page is named by its slug, and where a hand-written one states none, by its title — then by its
     * own content. Never by where it sits in the list: keyed on that, writing a page above another
     * reports the pages below as removed and re-added, and reads their edits onto their neighbours.
     *
     * @param  array<string, mixed>  $data
     */
    private static function pageKey(Page $page, array $data): string
    {
        if ($page->slug !== '') {
            return 'slug:'.$page->slug;
        }

        return $page->title !== null && $page->title !== '' ? 'title:'.$page->title : 'page:'.self::fingerprint($data);
    }

    /**
     * What tells two nodes apart once their identity and their structural key cannot. Identity members are
     * dropped: the id is already in the key, and provenance travels with it — a node whose only delta is
     * where it came from is not a changed node.
     *
     * @param  array<string, mixed>  $node
     */
    private static function fingerprint(array $node): string
    {
        unset($node['x-docuccino'], $node[NodeIdentity::FLAT_KEY], $node['provenance']);

        return substr(hash('sha256', Json::stable($node)), 0, 16);
    }

    /**
     * @param  OperationEntry  $entry
     */
    private function display(array $entry): string
    {
        return strtoupper($entry['method']).' '.$entry['path'];
    }

    /**
     * @param  array<string, mixed>|null  $content
     * @return array<string, array<string, mixed>>
     */
    private static function contentSchemas(?array $content): array
    {
        if ($content === null) {
            return [];
        }

        $out = [];
        foreach ($content as $media => $entry) {
            if (is_array($entry) && isset($entry['schema']) && is_array($entry['schema'])) {
                $out[(string) $media] = Arr::stringKeyed($entry['schema']);
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function requestBodySchemas(Operation $op): array
    {
        $requestBody = $op->rest['requestBody'] ?? null;

        if (! is_array($requestBody) || ! isset($requestBody['content']) || ! is_array($requestBody['content'])) {
            return [];
        }

        return self::contentSchemas(Arr::stringKeyed($requestBody['content']));
    }

    /**
     * `in` + `name` is how OAS tells one parameter from another, so it both names a parameter in the
     * report and keys it when there is no id to key on. A Reference Object states neither, so one naming a
     * component the document doesn't declare falls back to its pointer: unresolvable, but still the name of
     * a distinct parameter, where `?:?` is the same key for every one of them.
     */
    private static function paramLabel(Parameter $param): string
    {
        if ($param->name === null && $param->in === null) {
            $ref = $param->rest['$ref'] ?? null;

            if (is_string($ref) && $ref !== '') {
                return $ref;
            }
        }

        return ($param->in ?? '?').':'.($param->name ?? '?');
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private static function pageSlug(array $page): string
    {
        $slug = $page['slug'] ?? null;

        return is_string($slug) ? $slug : '?';
    }

    private static function nodeId(?string $id, string $parent, string $discriminator): string
    {
        return $id ?? $parent.'#'.$discriminator;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function schemaId(array $schema): ?string
    {
        return NodeIdentity::inArray($schema);
    }

    private static function parameterId(Parameter $param): ?string
    {
        return NodeIdentity::of($param->docuccino, $param->rest);
    }

    private static function responseId(ResponseObject $response): ?string
    {
        return NodeIdentity::of($response->docuccino, $response->rest);
    }

    private static function algoVersionOf(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $parts = explode(':', $id);

        return count($parts) === 3 && $parts[1] !== '' ? $parts[1] : null;
    }
}
