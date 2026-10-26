<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/** Why a document could not be read, as a value a caller can branch on. */
enum ReadFailure: string
{
    /** The bytes are not UTF-8 text. */
    case Encoding = 'encoding';

    /** The bytes are not JSON, or not YAML, as they were read. */
    case Syntax = 'syntax';

    /** The text parses, but to something other than an object at the root. */
    case NotAnObject = 'not-an-object';

    /**
     * YAML whose aliases expand it far past its own size, in values or in the text they hold — refused
     * rather than expanded.
     */
    case AliasExpansion = 'alias-expansion';

    /** A value JSON has no spelling for: an infinite or NaN number, or a string that is not UTF-8. */
    case Unrepresentable = 'unrepresentable';

    /** Nesting deeper than a document can be written back out. */
    case TooDeep = 'too-deep';

    /**
     * A document whose node index expands far past its own size — paths sharing one path item, each
     * listing that item's parameters for every operation — refused rather than indexed.
     */
    case NodeExpansion = 'node-expansion';
}
