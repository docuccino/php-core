<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * What one documented parameter wrote where this check looks for the thing that holds its values to
 * account — a schema, a content object, something that is neither, or nothing. {@see ParameterSchema}
 * reads it off a parameter object.
 *
 * ONE answer, rather than a nullable schema beside a boolean saying whether the null meant anything.
 * Three of the four are "nothing was checked" and they are three different facts about the document,
 * so they are three cases: a declaration in a grammar this does not decode, a member no reader can
 * take, and a document that says nothing at all. A reader that answers all three with null cannot tell
 * a check nobody can perform from a promise nobody looked at from a document with nothing to promise,
 * and those are different sentences for whoever reads the note. The boolean that told two of them
 * apart was opt-in, so forgetting it degraded the check in silence; a `match` over these that grows an
 * arm and forgets one is a build failure instead ({@see ContractChecker::checkParameter()}).
 *
 * Named for what the DOCUMENT holds rather than for what this can do with it — the one axis — because
 * only the first case's name would otherwise be about the reader.
 *
 * @internal
 */
enum ParameterSchemaKind
{
    /** `schema` holds a schema — an object, or the boolean `true`/`false`, which are schemas too. */
    case Schema;

    /** Documented with `content` instead: a real declaration, in a grammar this check does not decode. */
    case Content;

    /** `schema` or `content` is THERE and is not an object: a string, a number, a null. */
    case Malformed;

    /** Neither member, so there is no promise to have skipped. */
    case Absent;
}
