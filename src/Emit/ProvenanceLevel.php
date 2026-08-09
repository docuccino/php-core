<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

/**
 * Provenance emission level (design §4). Applies to UIR emission; the OAS emitters always
 * drop provenance entirely regardless of this setting.
 */
enum ProvenanceLevel: string
{
    case None = 'none';
    case Winners = 'winners';
    case Full = 'full';
}
