<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteDescriptor;

/**
 * Discovers the routes that belong to a document (design §6). Implementations are
 * container-resolved and late-bound; the adapter's built-in resolver reads the Laravel
 * router, but a third party registers one identically.
 */
interface RouteResolver
{
    /**
     * @return iterable<RouteDescriptor>
     */
    public function resolve(DocumentConfig $document): iterable;
}
