<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Support\Fqcn;

/**
 * A reflection-rich enum → schema mapper that supersedes the case-names-only {@see EnumTypeToSchema}
 * (it runs earlier in the chain). It documents a backed enum by its backing values (an integer schema
 * for an int-backed enum), attaches `#[CaseDescription]` prose as `x-enumDescriptions`, and honours
 * the `enums.naming` policy (`x-enumNames`/`x-enum-varnames`, off by default). A pure enum still lists
 * its case names, so it never regresses the plainer mapper. Reading `#[CaseDescription]` + the
 * representation naming policy is framework-neutral, so this is a core built-in mapper.
 *
 * Under the `enums.components` policy (default on), a reflectable enum is hoisted to a named
 * `components.schemas` entry — named by its short class name / `#[SchemaName]`, pinned to its FQCN
 * diff identity via {@see SchemaIdentity} + the {@see ComponentRegistry}
 * (deduped, so one enum → one component shared by every property and query-parameter item that uses it,
 * with its `x-enumDescriptions` carried once). The nullable composition of a `$ref` is handled honestly
 * by {@see UnionTypeToSchema} (a `$ref` cannot carry `type: [x, null]`, so it becomes an
 * `anyOf: [{$ref}, {type: null}]` under both nullable policies). With the policy off — or an enum that
 * is not reflectable here (no autoloadable definition to name/pin) — the schema stays inline, byte-for-
 * byte as before.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class EnumSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof EnumT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof EnumT) {
            return null;
        }

        // The enum's declaring file is a fragment-cache dependency: adding/removing a case changes
        // this schema (design §10). Recorded even when reflection later falls back to DType cases.
        $file = EnumReflection::file($type->fqcn);
        if ($file !== null) {
            $context->dependsOn($file);
        }

        $values = EnumReflection::values($type->fqcn);
        if ($values === []) {
            // The engine could not reflect the enum (e.g. it is not autoloadable here); fall back to
            // the case names the DType carries.
            $values = $type->cases;
        }

        if ($values === []) {
            return new SchemaResult(['type' => 'string'], 0.5);
        }

        $allInt = $values === array_filter($values, 'is_int');

        $schema = [
            'type' => $allInt ? 'integer' : 'string',
            'enum' => $allInt ? $values : array_map(strval(...), $values),
        ];

        $descriptions = EnumReflection::descriptions($type->fqcn);
        if ($descriptions !== []) {
            $schema['x-enumDescriptions'] = $descriptions;
        }

        // Codegen name hints (design §Representation policies): the case names, emitted alongside —
        // never replacing — the value-bearing `enum` member. Default `none` emits nothing.
        $naming = $context->representation()->enumNaming;
        if (($naming === 'x-enumNames' || $naming === 'x-enum-varnames') && $type->cases !== []) {
            $schema[$naming] = $type->cases;
        }

        // Hoist to a named component (default), so one enum is a single canonical, described schema
        // that properties and query-parameter item schemas $ref — deduped by FQCN identity. Only a
        // reflectable enum is hoisted: an un-autoloadable one has no honest name/identity to pin, so
        // it stays inline. Policy off restores the inline expression byte-for-byte.
        if ($context->representation()->enumComponents && enum_exists($type->fqcn)) {
            $name = SchemaIdentity::name($type->fqcn) ?? Fqcn::short($type->fqcn);
            $id = SchemaIdentity::id($type->fqcn) ?? $type->fqcn;

            return new SchemaResult($context->reference($name, $schema, $id), 0.95);
        }

        return new SchemaResult($schema, 0.95);
    }
}
