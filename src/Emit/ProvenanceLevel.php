<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

/**
 * How much provenance UIR emission keeps. The OAS emitters drop it entirely whatever this says.
 */
enum ProvenanceLevel: string
{
    case None = 'none';
    case Winners = 'winners';
    case Full = 'full';
}
