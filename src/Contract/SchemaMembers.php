<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Docuccino\Core\Draft\SchemaKeywords;

/**
 * How a walk over a schema the validator is handed reads each value it meets: a SCHEMA, whose members are
 * keywords; a map of NAMES, whose members are whatever the author called them, each holding a schema; a
 * LIST of schemas; or DATA, below which nothing is a keyword at all. Every walk here that rewrites or
 * follows a `$ref` asks this, never the name of the key holding a node, so a property called `default`
 * holds a schema like any other and a `$ref` inside a `const` is a value to compare.
 *
 * A keyword this does not name is read as a schema, since the validator's own vocabulary holds schemas
 * where no table here says so: that reaches no less far than the validator does, and a `$ref` rewritten
 * where nothing follows it changes no answer.
 *
 * @internal
 */
final class SchemaMembers
{
    /** A schema: its members are keywords. */
    public const string SCHEMA = 'schema';

    /** A map of names, each holding a schema. */
    public const string NAMES = 'names';

    /** A list of schemas. */
    public const string LIST = 'list';

    /** An instance, or lists of property names: nothing below it is a keyword. */
    public const string DATA = 'data';

    /**
     * The keywords whose value is an instance rather than a schema: compared (`const`, `enum`), filled in
     * (`default`) or published (`example`, `examples`).
     *
     * @var list<string>
     */
    private const array DATA_KEYWORDS = ['const', 'default', 'enum', 'example', 'examples'];

    /**
     * The maps of names the validator reads that no document keyword opens: draft-07's `dependencies`,
     * each name holding a schema or a list of names, and its own `$slots` and the `slots` a `$pragma`
     * sets, each holding a schema, a slot's name or a boolean. None of those but the schema is anything
     * to follow, so a map read as holding schemas throughout reads the rest harmlessly.
     *
     * @var list<string>
     */
    private const array VALIDATOR_NAME_MAPS = ['dependencies', '$slots', 'slots'];

    /** What the member `$name` of a value read as `$in` holds. */
    public static function member(string $name, string $in): string
    {
        return match ($in) {
            self::DATA => self::DATA,
            self::NAMES => self::SCHEMA,
            default => self::keyword($name),
        };
    }

    /** What an item of a list read as `$in` holds. */
    public static function item(string $in): string
    {
        return $in === self::DATA ? self::DATA : self::SCHEMA;
    }

    /** Whether the member `$name` of a value read as `$in` is a reference the validator follows. */
    public static function isReference(string $name, string $in): bool
    {
        return $name === '$ref' && ($in === self::SCHEMA || $in === self::LIST);
    }

    /**
     * Whether an empty array standing as the member `$name` of a value read as `$in` — or, where `$name`
     * is null, as one of its items — is the empty object: the value of a keyword that holds an object
     * ({@see SchemaKeywords::objectValued()}), and a schema standing in a map or a list of them. A list is
     * a legal value for none of those, so reading one as `{}` is not a guess.
     */
    public static function emptyIsObject(?string $name, string $in): bool
    {
        return match ($in) {
            self::SCHEMA => $name !== null && in_array($name, SchemaKeywords::objectValued(), true),
            self::NAMES => $name !== null,
            self::LIST => $name === null,
            default => false,
        };
    }

    private static function keyword(string $keyword): string
    {
        if (in_array($keyword, self::DATA_KEYWORDS, true) || str_starts_with($keyword, 'x-')) {
            return self::DATA;
        }

        return match (SchemaKeywords::positionOf($keyword)) {
            SchemaKeywords::POSITION_SCHEMA_MAP => self::NAMES,
            SchemaKeywords::POSITION_SCHEMA_LIST => self::LIST,
            SchemaKeywords::POSITION_STRING_LIST_MAP => self::DATA,
            default => in_array($keyword, self::VALIDATOR_NAME_MAPS, true) ? self::NAMES : self::SCHEMA,
        };
    }
}
