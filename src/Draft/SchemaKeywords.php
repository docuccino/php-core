<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

/**
 * The JSON Schema keyword classification {@see SchemaDraft::declareShape()} reasons over: which
 * keywords DESCRIBE a value's shape, which merely REFINE values of some type, and which are
 * annotations that say nothing about the value at all. Every keyword the document can carry is
 * classified once here, so no producer has to keep its own list of what its body replaces.
 *
 * A keyword this does not know is never superseded: we do not retract what we cannot read.
 *
 * @internal
 */
final class SchemaKeywords
{
    /**
     * The keywords that say what kind of value this is, and what is inside it. A declaration states
     * its shape whole, so the ones it leaves out no longer hold — an `additionalProperties` from a
     * map inference does not describe the closed shape that replaced it.
     *
     * @var list<string>
     */
    private const array SHAPE = [
        '$ref',
        'type',
        'nullable',
        'items',
        'prefixItems',
        'contains',
        'properties',
        'required',
        'additionalProperties',
        'patternProperties',
        'propertyNames',
        'dependentRequired',
        'dependentSchemas',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
        'discriminator',
    ];

    /**
     * The keywords that constrain values of a given instance type, mapped to the types they constrain
     * — a `minLength` speaks about strings and about nothing else. They survive a declaration that
     * restates their type (an inferred `format: date-time` is still true of a declared string) and go
     * when the declared shape is no longer a type they apply to. `[]` is every type, so nothing a
     * declaration can say makes them stale.
     *
     * @var array<string, list<string>>
     */
    private const array REFINEMENTS = [
        'format' => ['string', 'integer', 'number'],
        'enum' => [],
        'const' => [],
        'maxLength' => ['string'],
        'minLength' => ['string'],
        'pattern' => ['string'],
        'contentEncoding' => ['string'],
        'contentMediaType' => ['string'],
        'multipleOf' => ['integer', 'number'],
        'maximum' => ['integer', 'number'],
        'exclusiveMaximum' => ['integer', 'number'],
        'minimum' => ['integer', 'number'],
        'exclusiveMinimum' => ['integer', 'number'],
        'maxItems' => ['array'],
        'minItems' => ['array'],
        'uniqueItems' => ['array'],
        'maxProperties' => ['object'],
        'minProperties' => ['object'],
    ];

    /**
     * Documentation and identity, true of the value whatever shape it turns out to have. A declared
     * body never retracts these: the description a docblock wrote, or an example an author pinned,
     * is about the field rather than about the shape that field used to have.
     *
     * @var list<string>
     */
    private const array ANNOTATIONS = [
        'x-docuccino',
        '$id',
        '$anchor',
        '$defs',
        'title',
        'description',
        'default',
        'externalDocs',
        'example',
        'examples',
        'readOnly',
        'writeOnly',
        'deprecated',
    ];

    /**
     * Whether a schema states what kind of value it is — a `type`, or a `$ref` whose component states
     * it instead. That is what makes a write a declared SHAPE rather than a patch of one keyword, and
     * so what makes anything it leaves out superseded. A description or an example on its own
     * declares no shape and therefore supersedes nothing.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function statesShape(array $schema): bool
    {
        return array_key_exists('type', $schema) || array_key_exists('$ref', $schema);
    }

    /**
     * Whether a standing keyword no longer describes the value once `$declaration` states its shape.
     * A keyword the declaration restates is not superseded — it is simply overwritten, on precedence,
     * like any other write.
     *
     * @param  array<string, mixed>  $declaration
     */
    public static function isSuperseded(string $keyword, array $declaration): bool
    {
        if (array_key_exists($keyword, $declaration)) {
            return false;
        }

        if (in_array($keyword, self::SHAPE, true)) {
            return true;
        }

        $constrains = self::REFINEMENTS[$keyword] ?? null;

        if ($constrains === null || $constrains === []) {
            return false;
        }

        return array_intersect($constrains, self::declaredTypes($declaration)) === [];
    }

    /**
     * Every keyword this classifies, keyword => family. The classification is the thing that goes
     * stale, so the guard that reads the canonicalizer's keyword set compares against this rather
     * than against a second copy of the list.
     *
     * @return array<string, string>
     */
    public static function classification(): array
    {
        $out = [];

        foreach (self::SHAPE as $keyword) {
            $out[$keyword] = 'shape';
        }

        foreach (array_keys(self::REFINEMENTS) as $keyword) {
            $out[$keyword] = 'refinement';
        }

        foreach (self::ANNOTATIONS as $keyword) {
            $out[$keyword] = 'annotation';
        }

        return $out;
    }

    /**
     * The instance types a declaration says its value may be. A `$ref` names no type of its own here
     * — the component it points at carries the whole shape — so every type-bound refinement beside it
     * is stale, which is the same answer an unreadable `type` gets.
     *
     * @param  array<string, mixed>  $declaration
     * @return list<string>
     */
    private static function declaredTypes(array $declaration): array
    {
        $type = $declaration['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        if (is_array($type)) {
            return array_values(array_filter($type, 'is_string'));
        }

        return [];
    }
}
