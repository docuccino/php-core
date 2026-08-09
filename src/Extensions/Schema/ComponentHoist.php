<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\BuiltIn\ClassTypeToSchema;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Support\Fqcn;

/**
 * The shared skeleton for any schema mapper that lifts a class to a reusable `components.schemas`
 * entry — core's {@see ClassTypeToSchema} and the adapter's integration mappers (spatie Data, API
 * Resource, JSON:API resource, Eloquent model) all delegate the same dance:
 *
 * 1. cycle-break — a self-reference found mid-expansion returns a `$ref` to the class's reserved
 *    name rather than recursing into it forever;
 * 2. resolve the component name (`#[SchemaName]`, else the short class name) and diff identity
 *    (`#[SchemaId]`, else the FQCN) via {@see SchemaIdentity};
 * 3. reserve the final, possibly collision-suffixed name up front through
 *    {@see SchemaContext::reserveComponentName()} so that cycle-breaking `$ref` is accurate;
 * 4. materialise the body via {@see SchemaContext::reference()}, or degrade to a bare
 *    `{type: object}` at low confidence when the builder can't analyse the class.
 *
 * One instance lives per mapper — its `expanding` map is that mapper's recursion state — so the
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
     * Hoist `$fqcn` to a named component, calling `$build` between reserving the name and referencing
     * it. `$build` may recurse back through the chain into the same mapper — a self-reference is
     * cycle-broken via the reserved name — and returning null degrades to a bare `{type: object}`.
     *
     * `$schemaName`/`$schemaId` override the {@see SchemaIdentity} pair when a mapper reads them from
     * its own reflection (spatie facts, say); null falls back to the attribute pair.
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
