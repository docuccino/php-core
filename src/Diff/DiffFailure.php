<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/** Why two documents could not be compared, as a value a caller can branch on. */
enum DiffFailure: string
{
    /** Their identities were minted by different versions of the identity algorithm, so no node pairs. */
    case AlgorithmMismatch = 'algorithm-mismatch';

    /**
     * Comparing them reads one document's shared nodes through its references far more often than any
     * document needs — refused rather than compared.
     */
    case ReferenceExpansion = 'reference-expansion';
}
