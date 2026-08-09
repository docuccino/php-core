<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\Operation;
use Docuccino\Core\Support\Hydrate;

/**
 * The result of processing one route: the frozen operation, its OAS path and HTTP method, the
 * diagnostics raised while building it, and the transitive closure of component schemas and response
 * components it *references* — not just the ones it happened to register first. Carrying that
 * closure plus the diagnostics makes a fragment self-contained, so a warm cache hit reconstructs the
 * operation, restores every component it points at and replays its diagnostics without the type
 * engine — and can't leave a dangling `$ref` when the route that first owned a shared component goes
 * away.
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
