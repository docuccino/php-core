<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\Operation;
use Docuccino\Core\Extensions\Schema\ComponentNames;
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
     * @param  ?string  $actionClass  the controller/action class this route dispatches to, if any
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
        public ?string $actionClass = null,
    ) {}

    /**
     * The same fragment with every component reference put through a rename map — what a warm cache
     * hit needs when the component it restores lands under a name other than the one it was cached
     * with, because a route added since took that name first. Without it the restored operation would
     * keep `$ref`-ing a name now held by a DIFFERENT class's shape.
     *
     * @param  array<string, string>  $schemas  cached schema name → the name it registered under now
     * @param  array<string, string>  $responses  the same for `components.responses`
     */
    public function withRenamedComponents(array $schemas, array $responses): self
    {
        if ($schemas === [] && $responses === []) {
            return $this;
        }

        $operation = ComponentNames::rename($this->operation->toArray(), $schemas);
        $operation = ComponentNames::rename($operation, $responses, 'responses');

        $schemaIds = [];
        foreach ($this->componentSchemaIds as $name => $id) {
            $schemaIds[$schemas[$name] ?? $name] = $id;
        }

        /** @var array<string, mixed> $operation */
        return new self(
            path: $this->path,
            method: $this->method,
            operation: Operation::fromArray($operation),
            routeSignature: $this->routeSignature,
            diagnostics: $this->diagnostics,
            componentSchemas: ComponentNames::rekey($this->componentSchemas, $schemas),
            componentSchemaIds: $schemaIds,
            componentResponses: ComponentNames::rekey($this->componentResponses, $responses),
            actionClass: $this->actionClass,
        );
    }

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
            'actionClass' => $this->actionClass,
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
            actionClass: Hydrate::stringOrNull($data['actionClass'] ?? null),
        );
    }
}
