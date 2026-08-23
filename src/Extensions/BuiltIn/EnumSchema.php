<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Support\Fqcn;

/**
 * A reflection-rich enum → schema mapper that supersedes the case-names-only {@see EnumTypeToSchema}
 * by running earlier in the chain: it documents a backed enum by its backing values (an integer
 * schema for an int-backed one), attaches `#[CaseDescription]` prose as `x-enumDescriptions`, and
 * honours the `enums.naming` policy. A pure enum still lists its case names, so it never regresses
 * the plainer mapper. All framework-neutral, hence a core built-in.
 *
 * Under `enums.components` (default on) a reflectable enum hoists to a named component via
 * {@see SchemaIdentity} + the {@see ComponentRegistry}, deduped by FQCN identity — so one enum is one
 * described schema shared by every property and query-parameter item using it. Making a `$ref`
 * nullable is {@see UnionTypeToSchema}'s job: a `$ref` can't carry `type: [x, null]`, so it becomes
 * `anyOf: [{$ref}, {type: null}]` under both nullable policies.
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

        // The enum's declaring file is a fragment-cache dependency — adding or removing a case changes
        // this schema. Recorded even when reflection later falls back to the DType cases.
        $file = EnumReflection::file($type->fqcn);
        if ($file !== null) {
            $context->dependsOn($file);
        }

        $values = EnumReflection::values($type->fqcn);
        if ($values === []) {
            // Not reflectable here (not autoloadable) — fall back to the DType's case names.
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

        // Case names ride as codegen name hints alongside — never replacing — the value-bearing
        // `enum` member; descriptions in the shapes tools consume. One rulebook: EnumDecoration.
        $schema = EnumDecoration::apply(
            $schema,
            $context->representation()->enumNaming,
            $type->cases,
            EnumReflection::descriptions($type->fqcn),
        );

        // Only a reflectable enum hoists — an un-autoloadable one has no honest name or identity to
        // pin, so it stays inline, as does everything when the policy is off.
        if ($context->representation()->enumComponents && enum_exists($type->fqcn)) {
            $name = SchemaIdentity::name($type->fqcn) ?? Fqcn::short($type->fqcn);
            $id = SchemaIdentity::id($type->fqcn) ?? $type->fqcn;

            return new SchemaResult($context->reference($name, $schema, $id), 0.95);
        }

        return new SchemaResult($schema, 0.95);
    }
}
