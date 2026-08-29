<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * What presence means at a subschema position — the axis of {@see SchemaPolarity}'s decision that
 * settles whether the position, or one of its members, arriving or leaving gates a release. An enum
 * rather than string constants because a kind is minted here and never read off a document: every
 * `match` over it is exhaustive with no default, so a kind added with no verdict of its own is a
 * PHPStan failure at each table that owes it one instead of a conservative guess made at runtime.
 *
 * @internal
 */
enum SchemaMember: string
{
    /** Absent IS the empty schema (no `items` constrains no element), so presence needs no code. */
    case EmptySchema = 'empty';

    /** Absent is not the empty schema, and the position arriving narrows: `not: {}` rejects everything. */
    case Constraint = 'constraint';

    /** `contains`, which narrows only while its own bounds assert something. */
    case Bounded = 'bounded';

    /** A branch of `anyOf`/`oneOf` — and the keyword itself, which is a different question. */
    case Union = 'union';

    /** A `$defs` member: a store a `$ref` may name, not an assertion. */
    case Store = 'store';

    /** `properties`, which has a comparison of its own. */
    case Property = 'property';

    /** `dependentRequired`, whose members are lists of property names rather than subschemas. */
    case Required = 'required';
}
