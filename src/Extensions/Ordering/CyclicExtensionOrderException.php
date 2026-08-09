<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Ordering;

use RuntimeException;

/**
 * Thrown when `before`/`after` edges form a cycle, so no total order exists. A configuration
 * error surfaced loudly rather than resolved by silently dropping an edge.
 */
final class CyclicExtensionOrderException extends RuntimeException
{
    /**
     * @param  list<string>  $involved  the class names still unresolved when the cycle was detected
     */
    public function __construct(public readonly array $involved)
    {
        parent::__construct('Cyclic extension ordering among: '.implode(', ', $involved));
    }
}
