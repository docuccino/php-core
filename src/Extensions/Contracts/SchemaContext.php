<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\TypeEngine;

/**
 * The services a {@see TypeToSchema} mapper needs while converting a type (design §6): chain
 * recursion for nested types, component hoisting for named schemas, the engine for lazy class
 * expansion, and a way to lower confidence when a conversion is imprecise.
 */
interface SchemaContext
{
    /**
     * Convert a nested type through the full mapper chain. An unresolvable type yields `{}`, never null.
     *
     * @return array<string, mixed>
     */
    public function convert(DType $type): array;

    /**
     * Hoist a named schema into `components.schemas` — deduping structurally-equal registrations,
     * suffixing genuine collisions — and return a `{"$ref": …}` to it. `$schemaId` pins the
     * component's diff identity when known (an FQCN).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function reference(string $name, array $schema, ?string $schemaId = null): array;

    /**
     * Reserve the final component name a named schema will hoist under, before its body is built —
     * how a mapper expanding a self-referential class points the cycle-breaking `$ref` at the right
     * name. The registry is the sole owner of component naming and collision suffixing, so never
     * fabricate a component name or a `#/components/schemas/…` ref from a short name yourself.
     * `$schemaId` is the identity (an FQCN); reserving it twice returns the same name.
     */
    public function reserveComponentName(string $name, string $schemaId): string;

    /** The inference engine, for {@see TypeEngine::classMetadata()} class expansion. */
    public function engine(): TypeEngine;

    /**
     * Recursion depth of the running conversion: 1 at the top-level type (a response/parameter root),
     * deeper for each nested type. Read it if your output depends on being at the root — Laravel
     * resource `data` wrapping applies to the top-level resource only, for instance.
     */
    public function depth(): int;

    /**
     * Record files this conversion read whose contents affect the emitted schema — a reflected
     * Data/Model/Resource class, a `classMetadata` source, an enum cast's backing enum. Skip it and
     * editing that file leaves a warm fragment stale — see RouteContext::dependencies(). Empty
     * strings are ignored.
     */
    public function dependsOn(string ...$files): void;

    /** The document's representation policy (enum naming, nullable expression …). */
    public function representation(): RepresentationPolicy;

    /** Record that the current conversion is imprecise; the lowest value seen wins. */
    public function lowerConfidence(float $confidence): void;

    /**
     * Record a diagnostic raised while converting a type, e.g. a morph variant with no morph-map
     * alias. Folded into the document's diagnostic channel via the component registry.
     */
    public function diagnostic(Diagnostic $diagnostic): void;
}
