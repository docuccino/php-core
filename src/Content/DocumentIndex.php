<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Support\Hydrate;

/**
 * Read-only lookup over an assembled UIR document array, built once and shared by directive
 * resolution and nav-ref validation. Maps the human-facing references authors write (an
 * `operationId`, a `METHOD /path` signature, a component schema name, a tag name) to the stable
 * `x-docuccino` identities consumers link on.
 *
 * @internal
 */
final readonly class DocumentIndex
{
    /**
     * @param  array<string, string>  $operationsById  operationId → stable op id
     * @param  array<string, string>  $operationsBySignature  "METHOD /path" → stable op id
     * @param  array<string, string>  $schemasByName  component name → stable schema id
     * @param  array<string, true>  $tags  tag name → present
     * @param  list<Diagnostic>  $diagnostics  build-time warnings (duplicate operationId)
     */
    private function __construct(
        private array $operationsById,
        private array $operationsBySignature,
        private array $schemasByName,
        private array $tags,
        private array $diagnostics = [],
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public static function build(array $document): self
    {
        $operationsById = [];
        $operationsBySignature = [];
        $tags = [];
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];
        foreach ($paths as $template => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (PathItem::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (! is_array($operation)) {
                    continue;
                }

                $stableId = self::nodeId($operation);
                if ($stableId === null) {
                    continue;
                }

                $operationsBySignature[strtoupper($method).' '.$template] = $stableId;

                $operationId = $operation['operationId'] ?? null;
                if (is_string($operationId) && $operationId !== '') {
                    if (isset($operationsById[$operationId])) {
                        // Last-wins is deterministic but arbitrary: a ::operation{id="…"} directive
                        // would resolve to whichever operation came last in path iteration.
                        $diagnostics[] = new Diagnostic(
                            severity: Severity::Warning,
                            code: 'content.duplicate-operation-id',
                            message: sprintf('Duplicate operationId "%s"; a ::operation directive referencing it resolves to the last operation in path order.', $operationId),
                        );
                    }
                    $operationsById[$operationId] = $stableId;
                }

                foreach (Hydrate::stringList($operation['tags'] ?? null) as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        $schemasByName = [];
        $components = $document['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;
        if (is_array($schemas)) {
            foreach ($schemas as $name => $schema) {
                $id = is_array($schema) ? self::nodeId($schema) : null;
                if ($id !== null) {
                    $schemasByName[(string) $name] = $id;
                }
            }
        }

        $definitions = $document['tags'] ?? null;
        if (is_array($definitions)) {
            foreach ($definitions as $definition) {
                $name = is_array($definition) ? ($definition['name'] ?? null) : null;
                if (is_string($name) && $name !== '') {
                    $tags[$name] = true;
                }
            }
        }

        return new self($operationsById, $operationsBySignature, $schemasByName, $tags, $diagnostics);
    }

    /**
     * Build-time warnings surfaced by indexing (currently: duplicate operationId collisions). The
     * caller (content resolution) drains these into the document diagnostics.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Resolve an operation reference to its stable `op:` id. `$reference` is matched against
     * `operationId` first, then against a `METHOD /path` signature (so unnamed routes are still
     * addressable). Null when nothing matches.
     */
    public function resolveOperation(string $reference): ?string
    {
        $signature = $reference;
        if (str_contains($reference, ' ')) {
            [$method, $rest] = explode(' ', $reference, 2);
            $signature = strtoupper($method).' '.$rest; // method is case-insensitive; the path is not
        }

        return $this->operationsById[$reference]
            ?? $this->operationsBySignature[$signature]
            ?? null;
    }

    /** Resolve a component schema name to its stable `sch:` id, or null when absent. */
    public function resolveSchema(string $name): ?string
    {
        return $this->schemasByName[$name] ?? null;
    }

    public function hasTag(string $name): bool
    {
        return isset($this->tags[$name]);
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function nodeId(array $node): ?string
    {
        $extension = $node['x-docuccino'] ?? null;
        $id = is_array($extension) ? ($extension['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
