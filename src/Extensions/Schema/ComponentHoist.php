<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\BuiltIn\ClassTypeToSchema;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Support\Fqcn;

/**
 * The shared component-hoisting skeleton for any schema mapper that lifts a class to a reusable
 * `components.schemas` entry: core's built-in {@see ClassTypeToSchema}
 * and the adapter's integration mappers (a spatie Data class, an API Resource, a JSON:API resource,
 * an Eloquent model) all delegate the same three-step dance to it —
 *
 * 1. an expanding-map cycle-break: a self-reference discovered mid-expansion returns a `$ref` to
 *    the name reserved for the class rather than recursing into it (the guard against infinite
 *    recursion / stack overflow on a self-referential class);
 * 2. {@see SchemaIdentity} resolution of the component name (`#[SchemaName]`, else the short class
 *    name) and diff identity (`#[SchemaId]`, else the FQCN);
 * 3. reserving the final (possibly collision-suffixed) component name up front via
 *    {@see SchemaContext::reserveComponentName()} so the cycle-breaking `$ref` points at the exact
 *    name the registry will hoist under; and
 * 4. materialising the body through {@see SchemaContext::reference()}, or degrading to a bare
 *    `{type: object}` at low confidence when the builder cannot analyse the class.
 *
 * A single instance is held per mapper (its `expanding` map is the mapper's recursion state), so the
 * mapper stays effectively stateless between top-level conversions.
 */
final class ComponentHoist
{
    /**
     * @var array<string, string> FQCN currently mid-expansion → its reserved component name, so a
     *                            self-reference points its cycle-breaking `$ref` at the exact
     *                            (possibly suffixed) name the registry will hoist it under
     */
    private array $expanding = [];

    /**
     * Hoist `$fqcn` to a named component, calling `$build` to materialise its body between reserving
     * the name and referencing it. `$build` may recurse back through the chain into the same mapper
     * (self-reference is cycle-broken via the reserved name); returning null degrades the class to a
     * bare `{type: object}`.
     *
     * `$schemaName`/`$schemaId` override the resolved {@see SchemaIdentity} pair when a mapper reads
     * them from its own reflection (e.g. spatie facts) — pass null to fall back to the attribute pair.
     *
     * @param  callable(): (array<string, mixed>|null)  $build
     */
    public function hoist(SchemaContext $context, string $fqcn, callable $build, ?string $schemaName = null, ?string $schemaId = null): SchemaResult
    {
        if (isset($this->expanding[$fqcn])) {
            return new SchemaResult(['$ref' => '#/components/schemas/'.$this->expanding[$fqcn]], 0.9);
        }

        $id = $schemaId ?? SchemaIdentity::id($fqcn) ?? $fqcn;
        $name = $context->reserveComponentName($schemaName ?? SchemaIdentity::name($fqcn) ?? Fqcn::short($fqcn), $id);

        $this->expanding[$fqcn] = $name;
        $body = $build();
        unset($this->expanding[$fqcn]);

        if ($body === null) {
            return new SchemaResult(['type' => 'object'], 0.4);
        }

        return new SchemaResult($context->reference($name, $body, $id), 0.9);
    }
}
