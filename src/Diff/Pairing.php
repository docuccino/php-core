<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * How {@see DocumentDiffer} paired the two sides' nodes.
 *
 * `Identity` is the real thing: nodes match on their Docuccino id, so renaming a path parameter or
 * moving a body into a shared component is not a change. `Structural` is the fallback for an artifact
 * carrying no identities — nodes match on method + path (or component name), which is what every other
 * OpenAPI differ does, and which reads a rename as a removal plus an addition.
 */
enum Pairing: string
{
    case Identity = 'identity';

    case Structural = 'structural';
}
