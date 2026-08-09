<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\Operation;
use Docuccino\Core\Document\Parameter;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\Arr;

/**
 * The semantic diff engine (plan §"Easy wins"/design §2): compares two {@see UirDocument}s by
 * their stable `x-docuccino.id`s, so a path-parameter rename (same op id) reads as no change while a
 * URI change reads as remove + add. It walks operations, their parameters/responses/request
 * bodies, named component schemas and content pages, delegating field-level schema comparison
 * to {@see SchemaComparator}, and classifies each {@see Change} as breaking or not.
 *
 * Breaking rules (plan): removed operation/parameter/response, a status removed, a parameter
 * becoming required, an added required parameter, plus the schema-level rules SchemaComparator
 * owns (type narrowed/changed, enum value removed, required request property added). Additions,
 * description/prose edits, deprecations and provenance-only differences are non-breaking — and
 * because only modelled fields and schema structure are compared, a provenance-only delta
 * produces an empty changeset.
 *
 * Identities carry an algorithm version (`op:v1:…`); two documents minted by different algo
 * versions cannot be paired safely, so the differ refuses with
 * {@see IncomparableDocumentsException} rather than mis-report.
 */
final class DocumentDiffer
{
    public function __construct(
        private readonly SchemaComparator $schemas = new SchemaComparator,
    ) {}

    public function diff(UirDocument $old, UirDocument $new): Changeset
    {
        $this->assertComparable($old, $new);

        /** @var list<Change> $changes */
        $changes = [];

        $this->diffOperations($old, $new, $changes);
        $this->diffComponentSchemas($old, $new, $changes);
        $this->diffPages($old, $new, $changes);

        usort($changes, static fn (Change $a, Change $b): int => $a->sortKey() <=> $b->sortKey());

        return new Changeset($changes);
    }

    private function assertComparable(UirDocument $old, UirDocument $new): void
    {
        $oldAlgo = $this->firstAlgoVersion($old);
        $newAlgo = $this->firstAlgoVersion($new);

        if ($oldAlgo !== null && $newAlgo !== null && $oldAlgo !== $newAlgo) {
            throw IncomparableDocumentsException::algoMismatch($oldAlgo, $newAlgo);
        }
    }

    private function firstAlgoVersion(UirDocument $document): ?string
    {
        foreach ($this->operations($document) as [$op]) {
            $id = $op->docuccino?->id;
            $algo = self::algoVersionOf($id);
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
    private function diffOperations(UirDocument $old, UirDocument $new, array &$changes): void
    {
        $oldOps = $this->indexOperations($old);
        $newOps = $this->indexOperations($new);

        foreach (Arr::sortedUnion(array_keys($oldOps), array_keys($newOps)) as $key) {
            $inOld = array_key_exists($key, $oldOps);
            $inNew = array_key_exists($key, $newOps);

            if (! $inNew) {
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Operation, $key, $this->display($oldOps[$key]), true, 'operation.removed');
            } elseif (! $inOld) {
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Operation, $key, $this->display($newOps[$key]), false, 'operation.added');
            } else {
                $this->diffOperationPair($key, $oldOps[$key], $newOps[$key], $changes);
            }
        }
    }

    /**
     * @param  array{path: string, method: string, op: Operation}  $old
     * @param  array{path: string, method: string, op: Operation}  $new
     * @param  list<Change>  $changes
     */
    private function diffOperationPair(string $id, array $old, array $new, array &$changes): void
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
        $this->diffParameters($id, $path, $oldOp, $newOp, $changes);
        $this->diffResponses($id, $path, $oldOp, $newOp, $changes);
        $this->diffRequestBody($id, $path, $oldOp, $newOp, $changes);
    }

    /**
     * Diffs an operation's `security` requirements. Adding a requirement forces existing clients to
     * satisfy authentication they did not before (breaking); removing one relaxes access (non-breaking).
     * Each requirement is compared as a whole by its canonical JSON, so a reordered scheme map is not
     * a change.
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
     * A requirement's canonical key → the requirement itself, so added/removed requirements are set
     * differences insensitive to declaration order and scheme-map key order.
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
     * @param  list<Change>  $changes
     */
    private function diffParameters(string $opId, string $path, Operation $old, Operation $new, array &$changes): void
    {
        $oldParams = $this->indexParameters($old);
        $newParams = $this->indexParameters($new);

        foreach (Arr::sortedUnion(array_keys($oldParams), array_keys($newParams)) as $key) {
            $inOld = array_key_exists($key, $oldParams);
            $inNew = array_key_exists($key, $newParams);

            if (! $inNew) {
                $param = $oldParams[$key];
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Parameter, self::nodeId($param->docuccino?->id, $opId, $key), $path.' parameters '.self::paramLabel($param), true, 'parameter.removed');
            } elseif (! $inOld) {
                $param = $newParams[$key];
                $breaking = $param->required === true;
                $code = $breaking ? 'parameter.added-required' : 'parameter.added';
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Parameter, self::nodeId($param->docuccino?->id, $opId, $key), $path.' parameters '.self::paramLabel($param), $breaking, $code);
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
        $id = self::nodeId($new->docuccino?->id, $opId, $key);
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
    private function diffResponses(string $opId, string $path, Operation $old, Operation $new, array &$changes): void
    {
        $oldResponses = $old->responses;
        $newResponses = $new->responses;

        foreach (Arr::sortedUnion(array_keys($oldResponses), array_keys($newResponses)) as $status) {
            $inOld = array_key_exists($status, $oldResponses);
            $inNew = array_key_exists($status, $newResponses);

            if (! $inNew) {
                $response = $oldResponses[$status];
                $changes[] = new Change(ChangeKind::Removed, ChangeTarget::Response, self::nodeId($response->docuccino?->id, $opId, $status), $path.' responses '.$status, true, 'response.removed');
            } elseif (! $inOld) {
                $response = $newResponses[$status];
                $changes[] = new Change(ChangeKind::Added, ChangeTarget::Response, self::nodeId($response->docuccino?->id, $opId, $status), $path.' responses '.$status, false, 'response.added');
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
        $id = self::nodeId($new->docuccino?->id, $opId, $status);
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
    private function diffComponentSchemas(UirDocument $old, UirDocument $new, array &$changes): void
    {
        $oldSchemas = $this->indexComponentSchemas($old);
        $newSchemas = $this->indexComponentSchemas($new);

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
        $oldPages = $this->indexPages($old);
        $newPages = $this->indexPages($new);

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
     * @return array<string, array{path: string, method: string, op: Operation}>
     */
    private function indexOperations(UirDocument $document): array
    {
        $out = [];

        foreach ($this->operations($document) as [$op, $method, $path]) {
            $id = $op->docuccino?->id;
            $key = $id ?? '_'.strtoupper($method).' '.$path;
            $out[$key] = ['path' => $path, 'method' => $method, 'op' => $op];
        }

        return $out;
    }

    /**
     * @return list<array{0: Operation, 1: string, 2: string}>
     */
    private function operations(UirDocument $document): array
    {
        $out = [];

        foreach ($document->paths ?? [] as $template => $item) {
            foreach ($item->operations as $method => $op) {
                $out[] = [$op, $method, (string) $template];
            }
        }

        return $out;
    }

    /**
     * @return array<string, Parameter>
     */
    private function indexParameters(Operation $op): array
    {
        $out = [];

        foreach ($op->parameters as $param) {
            $id = $param->docuccino?->id;
            $key = $id ?? self::paramLabel($param);
            $out[$key] = $param;
        }

        return $out;
    }

    /**
     * @return array<string, array{name: string, schema: array<string, mixed>}>
     */
    private function indexComponentSchemas(UirDocument $document): array
    {
        $out = [];

        $components = $document->components;
        if ($components === null) {
            return $out;
        }

        foreach ($components->schemas as $name => $schema) {
            $data = $schema->toArray();
            $id = self::schemaId($data);
            $key = $id ?? 'name:'.$name;
            $out[$key] = ['name' => (string) $name, 'schema' => $data];
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexPages(UirDocument $document): array
    {
        $out = [];

        $content = $document->docuccino?->content;
        if ($content === null) {
            return $out;
        }

        foreach ($content->pages as $page) {
            $stringKeyed = $page->toArray();
            $key = $page->id !== '' ? $page->id : ($page->slug !== '' ? 'slug:'.$page->slug : 'page:'.count($out));
            $out[$key] = $stringKeyed;
        }

        return $out;
    }

    /**
     * @param  array{path: string, method: string, op: Operation}  $entry
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

    private static function paramLabel(Parameter $param): string
    {
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
        $docuccino = $schema['x-docuccino'] ?? null;

        if (is_array($docuccino) && isset($docuccino['id']) && is_string($docuccino['id'])) {
            return $docuccino['id'];
        }

        return null;
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
