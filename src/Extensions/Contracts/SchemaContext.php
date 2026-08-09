<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\TypeEngine;

/**
 * The services a {@see TypeToSchema} mapper needs while converting a type (design §6):
 * chain recursion for nested types, component hoisting for named schemas, the engine for
 * lazy class expansion, and a provenance helper to lower the running confidence when a
 * conversion is imprecise.
 */
interface SchemaContext
{
    /**
     * Convert a nested type through the full mapper chain, returning its JSON Schema array.
     * Never returns null — an unresolvable type yields `{}`.
     *
     * @return array<string, mixed>
     */
    public function convert(DType $type): array;

    /**
     * Hoist a named component schema into `components.schemas` (deduping structurally-equal
     * registrations, suffixing genuine collisions) and return a `{"$ref": …}` array pointing
     * at it. `$schemaId` pins the component's diff identity when known (an FQCN).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function reference(string $name, array $schema, ?string $schemaId = null): array;

    /**
     * Reserve (and return) the final component name the registry will hoist a named schema under,
     * before its body is built. The registry is the single owner of component naming and collision
     * suffixing, so a mapper expanding a self-referential class can point the cycle-breaking `$ref`
     * at the exact name the schema will land under — callers must never fabricate a component name
     * (or a `#/components/schemas/…` ref) from a raw short name themselves. `$schemaId` is the
     * component's identity (an FQCN); reserving the same identity twice returns the same name.
     */
    public function reserveComponentName(string $name, string $schemaId): string;

    /** The inference engine, for {@see TypeEngine::classMetadata()} class expansion. */
    public function engine(): TypeEngine;

    /**
     * The recursion depth of the running conversion: 1 while a mapper converts the top-level type
     * (a response/parameter root), deeper for every nested type it recurses into. A mapper whose
     * output depends on whether the type is the document root — e.g. Laravel resource `data`
     * wrapping, which applies only to the top-level resource, never a nested one — reads this.
     */
    public function depth(): int;

    /**
     * Record that this conversion read one or more files whose contents affect the emitted schema
     * (a reflected Data/Model/Resource class, a `classMetadata` source file, an enum cast's backing
     * enum) so they join the route's fragment cache dependency manifest (design §10, "Fragment cache
     * soundness"). Without this, editing a returned DTO/model/enum would leave a warm fragment stale.
     * Empty strings are ignored.
     */
    public function dependsOn(string ...$files): void;

    /** The document's representation policy (enum naming, nullable expression …). */
    public function representation(): RepresentationPolicy;

    /** Record that the current conversion is imprecise; the lowest value seen wins. */
    public function lowerConfidence(float $confidence): void;

    /**
     * Record a diagnostic raised while converting a type (e.g. a polymorphic morph variant with no
     * morph-map alias). Folded into the document's diagnostic channel via the component registry.
     */
    public function diagnostic(Diagnostic $diagnostic): void;
}
