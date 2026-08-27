<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

/**
 * What the JSON Schema keywords MEAN, in the one place anything that reads a schema asks. Three
 * questions live here: the classification {@see SchemaDraft::declareShape()} reasons over — shape,
 * refinement or annotation — the subschema position {@see SUBSCHEMA_POSITIONS} a keyword's
 * value occupies, which is what tells a reader an array there is a JSON object rather than a list,
 * and which keywords a semantic DIFF may treat as a non-event ({@see ANNOTATION_ONLY}, a narrower
 * question than the first). Every keyword the document can carry is answered once here, so no
 * producer keeps its own copy.
 *
 * A keyword this does not know is never superseded: we do not retract what we cannot read.
 *
 * @internal
 */
final class SchemaKeywords
{
    /** A single subschema (or, in every one of these positions, a boolean schema). */
    public const string POSITION_SCHEMA = 'schema';

    /** A map of subschemas — the keys are property/pattern/`$defs` names, the values recurse. */
    public const string POSITION_SCHEMA_MAP = 'schemaMap';

    /** A list of subschemas. The only position here whose JSON value is an ARRAY. */
    public const string POSITION_SCHEMA_LIST = 'schemaList';

    /** A map of string lists — `dependentRequired` and nothing else. */
    public const string POSITION_STRING_LIST_MAP = 'stringListMap';

    /**
     * Where a keyword's value sits relative to the schema carrying it — the keyword's own contract, and
     * the only thing that can tell a reader whether an array there is a JSON object or a list. A keyword
     * named here needs no further entry anywhere; what derives from it, and why draft-07's `dependencies`
     * has no row and cannot have one, are in docs/design/uir-and-extensions.md §1 "The empty-object
     * invariant". `dependentSchemas` and `dependentRequired`, 2020-12's split of that keyword, are both here.
     *
     * @var array<string, string>
     */
    private const array SUBSCHEMA_POSITIONS = [
        'additionalItems' => self::POSITION_SCHEMA,
        'additionalProperties' => self::POSITION_SCHEMA,
        'allOf' => self::POSITION_SCHEMA_LIST,
        'anyOf' => self::POSITION_SCHEMA_LIST,
        'contains' => self::POSITION_SCHEMA,
        'contentSchema' => self::POSITION_SCHEMA,
        '$defs' => self::POSITION_SCHEMA_MAP,
        'definitions' => self::POSITION_SCHEMA_MAP,
        'dependentRequired' => self::POSITION_STRING_LIST_MAP,
        'dependentSchemas' => self::POSITION_SCHEMA_MAP,
        'else' => self::POSITION_SCHEMA,
        'if' => self::POSITION_SCHEMA,
        'items' => self::POSITION_SCHEMA,
        'not' => self::POSITION_SCHEMA,
        'oneOf' => self::POSITION_SCHEMA_LIST,
        'patternProperties' => self::POSITION_SCHEMA_MAP,
        'prefixItems' => self::POSITION_SCHEMA_LIST,
        'properties' => self::POSITION_SCHEMA_MAP,
        'propertyNames' => self::POSITION_SCHEMA,
        'then' => self::POSITION_SCHEMA,
        'unevaluatedItems' => self::POSITION_SCHEMA,
        'unevaluatedProperties' => self::POSITION_SCHEMA,
    ];

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
        'additionalItems',
        'contains',
        'unevaluatedItems',
        'properties',
        'required',
        'additionalProperties',
        'patternProperties',
        'propertyNames',
        'unevaluatedProperties',
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
        // A subschema, but one describing the string's DECODED content rather than the string — so it
        // is type-bound like its two siblings above, not a shape claim about the value carrying it.
        'contentSchema' => ['string'],
        'multipleOf' => ['integer', 'number'],
        'maximum' => ['integer', 'number'],
        'exclusiveMaximum' => ['integer', 'number'],
        'minimum' => ['integer', 'number'],
        'exclusiveMinimum' => ['integer', 'number'],
        'maxItems' => ['array'],
        'minItems' => ['array'],
        'uniqueItems' => ['array'],
        // Bounds on how many items `contains` has to match. `contains` is a shape keyword and goes with
        // the shape; these two are array refinements in their own right, so they go the same way a
        // `minItems` does rather than surviving onto whatever replaced the array.
        'maxContains' => ['array'],
        'minContains' => ['array'],
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
        // The dialect the schema is written in, and a note for the schema's own readers. Neither says
        // anything about the instance, so a declared shape leaves both where it found them.
        '$schema',
        '$comment',
        '$defs',
        // Draft-07's spelling of `$defs`. A store of subschemas says nothing about the value that
        // carries it, so neither is retracted by a declared shape.
        'definitions',
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
     * The annotation keywords a DIFF may treat as a non-event: each says what a value means and none
     * says what it may be, so editing one moves no contract and gates nothing under any versioning
     * policy. `SchemaComparator` is the reader.
     *
     * A different question from {@see ANNOTATIONS}, which is about supersession — which standing
     * keywords a declared shape retracts — and is deliberately wider. Everything it holds and this does
     * not is here for one of three reasons. `default`, `readOnly`, `writeOnly` and `deprecated` say
     * what the server fills in, whether a value may be sent, whether it will come back and whether it
     * is being withdrawn — a generator emits different code for each. `$defs`, `definitions`, `$id` and
     * `$anchor` are the contract under another name, because a `$ref` can point at any of them. And
     * `$schema` names the dialect every keyword beside it is read in, while `x-docuccino` carries the
     * identity the diff pairs nodes BY.
     *
     * Widening this set silences a real change, so both halves of the split are pinned by dataset —
     * every member, and every one of the ten exclusions.
     *
     * @var list<string>
     */
    private const array ANNOTATION_ONLY = [
        '$comment',
        'description',
        'example',
        'examples',
        'externalDocs',
        'title',
    ];

    /** Whether changing `$keyword` alone can change nothing a client may do. {@see ANNOTATION_ONLY} */
    public static function isAnnotationOnly(string $keyword): bool
    {
        return in_array($keyword, self::ANNOTATION_ONLY, true);
    }

    /**
     * Every annotation-only keyword, so a guard reads the set rather than a second copy of it.
     *
     * @return list<string>
     */
    public static function annotationOnly(): array
    {
        return self::ANNOTATION_ONLY;
    }

    /**
     * Every supersession annotation. The exclusions {@see ANNOTATION_ONLY} reasons about are this set
     * minus that one, which is the only way a guard can see them at all — a new annotation landing in
     * neither list is a decision nobody made.
     *
     * @return list<string>
     */
    public static function annotations(): array
    {
        return self::ANNOTATIONS;
    }

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
     * The subschema position `$keyword` occupies, or null where it carries no subschema at all.
     * One of the `POSITION_*` constants.
     */
    public static function positionOf(string $keyword): ?string
    {
        return self::SUBSCHEMA_POSITIONS[$keyword] ?? null;
    }

    /**
     * Every keyword whose JSON value is an OBJECT — so every keyword at which an empty PHP array is
     * the empty object rather than the empty list. That is all of them bar the list-valued
     * applicators, whose `[]` genuinely is a list.
     *
     * @return list<string>
     */
    public static function objectValued(): array
    {
        return array_keys(array_filter(
            self::SUBSCHEMA_POSITIONS,
            static fn (string $position): bool => $position !== self::POSITION_SCHEMA_LIST,
        ));
    }

    /**
     * Every keyword sitting at `$position`.
     *
     * @return list<string>
     */
    public static function at(string $position): array
    {
        return array_keys(array_filter(
            self::SUBSCHEMA_POSITIONS,
            static fn (string $candidate): bool => $candidate === $position,
        ));
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
