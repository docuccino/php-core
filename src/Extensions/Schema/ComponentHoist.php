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
 * 1. resolve the component name (`#[SchemaName]`, else the short class name) and diff identity
 *    (`#[SchemaId]`, else the FQCN) via {@see SchemaIdentity};
 * 2. cycle-break — a self-reference found mid-expansion returns a `$ref` rather than recursing into
 *    the class forever, taking a name through {@see SchemaContext::reserveComponentName()} at the
 *    moment it needs one;
 * 3. materialise the body via {@see SchemaContext::reference()}, or degrade to a bare
 *    `{type: object}` at low confidence when the builder can't analyse the class.
 *
 * A class that describes ITSELF with `#[Description]` is described here rather than in each mapper, so
 * every class this lifts gets it from one place ({@see ClassAnnotations}) — a body that already carries
 * a description keeps it.
 *
 * A name is only ever reserved for a `$ref` that has already gone out, and the expansion state is
 * unwound whatever the builder does — an engine failure mid-build must not leave a class marked
 * expanding for the rest of the run, since one mapper is resolved once and sees every route.
 *
 * One instance lives per mapper — its `expanding` set is that mapper's recursion state — so the
 * mapper stays effectively stateless between top-level conversions.
 */
final class ComponentHoist
{
    /** @var array<string, true> the FQCNs this mapper is currently mid-expansion on */
    private array $expanding = [];

    /** @var array<string, string> FQCN → the name a self-reference took for it while it expanded */
    private array $reservations = [];

    /**
     * Hoist `$fqcn` to a named component, calling `$build` and referencing what it returns. `$build`
     * may recurse back through the chain into the same mapper — a self-reference is cycle-broken with
     * a `$ref` — and returning null degrades to a bare `{type: object}`.
     *
     * `$schemaName`/`$schemaId` override the {@see SchemaIdentity} pair when a mapper reads them from
     * its own reflection (spatie facts, say); null falls back to the attribute pair.
     *
     * @param  callable(): (array<string, mixed>|null)  $build
     */
    public function hoist(SchemaContext $context, string $fqcn, callable $build, ?string $schemaName = null, ?string $schemaId = null): SchemaResult
    {
        $id = $schemaId ?? SchemaIdentity::publishedId($fqcn);
        $name = $schemaName ?? SchemaIdentity::name($fqcn) ?? Fqcn::short($fqcn);

        // Adding a `#[Description]` has to invalidate the fragment that published without one.
        $context->dependsOn(...DeclarationFiles::of($fqcn));

        if (isset($this->expanding[$fqcn])) {
            $this->reservations[$fqcn] ??= $context->reserveComponentName($name, $id);

            return new SchemaResult(['$ref' => '#/components/schemas/'.$this->reservations[$fqcn]], 0.9);
        }

        $this->expanding[$fqcn] = true;

        try {
            $body = $build();
            $reserved = $this->reservations[$fqcn] ?? null;
        } finally {
            unset($this->expanding[$fqcn], $this->reservations[$fqcn]);
        }

        if ($body === null) {
            // Nothing to publish. A self-reference that already took a name has to resolve to
            // something, so the degraded object goes out under it rather than leaving a dangling `$ref`.
            // It still says what the class IS: a shape that could not be analysed is exactly where the
            // one sentence the author wrote about it is worth most.
            $degraded = ClassAnnotations::applyTo($context, ['type' => 'object'], $fqcn);

            return $reserved === null
                ? new SchemaResult($degraded, 0.4)
                : new SchemaResult($context->reference($reserved, $degraded, $id), 0.4);
        }

        return new SchemaResult($context->reference($name, ClassAnnotations::applyTo($context, $body, $fqcn), $id), 0.9);
    }
}
