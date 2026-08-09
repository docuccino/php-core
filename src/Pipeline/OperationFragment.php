<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\Operation;
use Docuccino\Core\Support\Hydrate;

/**
 * The result of processing one route (design §5 / §10): the frozen operation, the OAS path it
 * belongs under, its HTTP method, the diagnostics raised while building it, and the transitive
 * closure of reusable component schemas AND response components it references (arch F7 — not merely
 * the ones it registered first). Carrying the referenced closure + diagnostics makes the fragment
 * the self-contained cache unit — a warm cache hit can reconstruct the operation, restore every
 * component it points at, and replay its diagnostics without touching the type engine, and can never
 * leave a dangling `$ref` when the route that first owned a shared component is removed.
 *
 * @internal
 */
final readonly class OperationFragment
{
    /**
     * @param  list<Diagnostic>  $diagnostics
     * @param  array<string, array<string, mixed>>  $componentSchemas  name → schema this operation references (transitive closure)
     * @param  array<string, string>  $componentSchemaIds  name → schemaId (FQCN) for diff identity
     * @param  array<string, array<string, mixed>>  $componentResponses  name → response component this operation references (transitive closure)
     */
    public function __construct(
        public string $path,
        public string $method,
        public Operation $operation,
        public string $routeSignature,
        public array $diagnostics = [],
        public array $componentSchemas = [],
        public array $componentSchemaIds = [],
        public array $componentResponses = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'method' => $this->method,
            'operation' => $this->operation->toArray(),
            'routeSignature' => $this->routeSignature,
            'diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $this->diagnostics),
            'componentSchemas' => $this->componentSchemas,
            'componentSchemaIds' => $this->componentSchemaIds,
            'componentResponses' => $this->componentResponses,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $operation */
        $operation = is_array($data['operation'] ?? null) ? $data['operation'] : [];

        return new self(
            path: is_string($data['path'] ?? null) ? $data['path'] : '',
            method: is_string($data['method'] ?? null) ? $data['method'] : 'get',
            operation: Operation::fromArray($operation),
            routeSignature: is_string($data['routeSignature'] ?? null) ? $data['routeSignature'] : '',
            diagnostics: Hydrate::listOf($data['diagnostics'] ?? null, Diagnostic::fromArray(...)),
            componentSchemas: Hydrate::mapOfArrays($data['componentSchemas'] ?? null),
            componentSchemaIds: Hydrate::stringMap($data['componentSchemaIds'] ?? null),
            componentResponses: Hydrate::mapOfArrays($data['componentResponses'] ?? null),
        );
    }
}
