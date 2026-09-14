<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Support\Fqcn;

/**
 * Where an enum class becomes a published component: its body, its name, its identity, and the sentence
 * it states about itself. One place, because more than one producer reaches an enum — the type chain
 * from a property, a validation rule from a request body, a Query Builder filter — and a name is a type
 * name in somebody's generated client.
 *
 * Two producers naming or shaping one enum differently do NOT publish two components for it: the
 * registry reuses a slot by identity, so the second body is discarded and the document keeps whichever
 * producer registered first — encounter order deciding what a generated client's type says. That is the
 * divergence this class exists to prevent, and it is why a producer publishing the set INLINE asks here
 * too ({@see description()}): an inline body is registered nowhere, so nothing would reconcile it at all.
 *
 * The body is built from the enum's own declaration order, never from the order the producer that got
 * there first happened to list its values, so what the component says is a function of the enum.
 */
final class EnumComponent
{
    /**
     * Whether this class hoists at all. Only a reflectable enum has an honest name and identity to pin;
     * everything stays inline when the policy is off.
     */
    public static function hoists(string $fqcn, SchemaContext $context): bool
    {
        return $context->representation()->enumComponents && enum_exists($fqcn);
    }

    /**
     * The published body for a reflectable enum — backing values, typed by them, decorated with the
     * case names and `#[CaseDescription]` prose, and described by the enum's own `#[Description]`.
     * Null when the class is not a reflectable enum.
     *
     * @return array<string, mixed>|null
     */
    public static function body(string $fqcn, SchemaContext $context): ?array
    {
        $values = EnumReflection::values($fqcn);
        if ($values === []) {
            return null;
        }

        $allInt = $values === array_filter($values, 'is_int');

        $schema = EnumDecoration::apply(
            [
                'type' => $allInt ? 'integer' : 'string',
                'enum' => $allInt ? $values : array_map(strval(...), $values),
            ],
            $context->representation()->enumNaming,
            EnumReflection::names($fqcn),
            EnumReflection::descriptions($fqcn),
        );

        $description = self::description($fqcn, $context);
        if ($description !== null) {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /**
     * The sentence this enum states about itself, for a producer publishing its set INLINE — where there
     * is no component body to carry it, and so nothing else would. An enum is a class, so the sentence is
     * read and refused on the same terms as any other schema class's ({@see ClassAnnotations}): null both
     * where the enum states none and where what it states is not something a schema can hold, with the
     * refusal reported exactly as it is on a property or a DTO.
     *
     * Asking is also what records the enum's declaration files, here rather than at each producer: adding
     * or removing a `#[Description]`, a case or a `#[CaseDescription]` has to retire the fragment that
     * published without it, and a producer added later gets that for free by asking at all.
     */
    public static function description(string $fqcn, SchemaContext $context): ?string
    {
        $context->dependsOn(...DeclarationFiles::of($fqcn));

        $description = ClassAnnotations::applyTo($context, [], $fqcn)['description'] ?? null;

        return is_string($description) ? $description : null;
    }

    /**
     * The `$ref` for this enum's component, materialising `$schema` as its body.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    public static function reference(string $fqcn, array $schema, SchemaContext $context): array
    {
        /** @var array<string, string> */
        return $context->reference(
            SchemaIdentity::name($fqcn) ?? Fqcn::short($fqcn),
            $schema,
            SchemaIdentity::publishedId($fqcn),
        );
    }
}
