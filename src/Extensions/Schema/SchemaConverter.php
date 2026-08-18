<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteDependencies;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeSchemaConverter;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\TypeEngine;

/**
 * Drives the {@see TypeToSchema} chain (design §6): asks each mapper in order and takes the first that
 * supports the type and returns a non-null result. Nested types recurse through {@see convert()}, so
 * mappers compose without knowing the chain. It's also the {@see SchemaContext} they receive, and it
 * keeps the lowest confidence any mapper reported across a whole top-level conversion.
 *
 * The class is internal; what extensions are handed is {@see TypeSchemaConverter}, which is the public
 * half of this — so a method only declared here is not surface anyone may rely on.
 *
 * @internal
 */
final class SchemaConverter implements TypeSchemaConverter
{
    private float $confidence = 1.0;

    /** Recursion depth: 1 at the top-level type, deeper for nested ones. */
    private int $depth = 0;

    /**
     * @param  list<TypeToSchema>  $mappers
     */
    public function __construct(
        private readonly array $mappers,
        private readonly TypeEngine $typeEngine,
        private readonly ComponentRegistry $components,
        private readonly RepresentationPolicy $representation = new RepresentationPolicy,
        private readonly RouteDependencies $dependencies = new RouteDependencies,
    ) {}

    /**
     * Convert a top-level type, returning the schema and the confidence accumulated across it
     * and every nested conversion it triggered.
     */
    public function toSchema(DType $type): SchemaResult
    {
        $this->confidence = 1.0;
        $this->depth = 0;
        $schema = $this->convert($type);

        return new SchemaResult($schema, $this->confidence);
    }

    public function convert(DType $type): array
    {
        $this->depth++;

        try {
            foreach ($this->mappers as $mapper) {
                if (! $mapper->supports($type)) {
                    continue;
                }

                $result = $mapper->toSchema($type, $this);
                if ($result !== null) {
                    $this->lowerConfidence($result->confidence);

                    return $result->schema;
                }
            }

            // No mapper resolved the type — emit an open `{}` schema at low confidence.
            $this->lowerConfidence(0.1);

            return [];
        } finally {
            $this->depth--;
        }
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function reference(string $name, array $schema, ?string $schemaId = null): array
    {
        return $this->components->reference($name, $schema, $schemaId);
    }

    public function reserveComponentName(string $name, string $schemaId): string
    {
        return $this->components->reserveSchemaName($name, $schemaId);
    }

    public function engine(): TypeEngine
    {
        return $this->typeEngine;
    }

    public function dependsOn(string ...$files): void
    {
        $this->dependencies->addFiles(array_values($files));
    }

    public function representation(): RepresentationPolicy
    {
        return $this->representation;
    }

    public function lowerConfidence(float $confidence): void
    {
        $this->confidence = min($this->confidence, $confidence);
    }

    public function diagnostic(Diagnostic $diagnostic): void
    {
        $this->components->addDiagnostic($diagnostic);
    }

    public function components(): ComponentRegistry
    {
        return $this->components;
    }
}
