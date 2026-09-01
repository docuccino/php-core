<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;

/**
 * The assembled document array plus any diagnostics raised while merging fragments, hoisting
 * components and applying overlays.
 *
 * @internal
 */
final readonly class AssemblyResult
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<Diagnostic>  $diagnostics
     * @param  array<string, string>  $schemaSources  node id => the {@see SchemaIdentity::publishedId()}
     *                                                that component's schema was published for, which
     *                                                is the producing class unless it pinned an id of
     *                                                its own. The document itself carries only the
     *                                                node id, and nothing can read a class name back
     *                                                out of one — so this is how a reader turns a
     *                                                schema the document publishes into the class a
     *                                                version change has to name.
     */
    public function __construct(
        public array $document,
        public array $diagnostics = [],
        public array $schemaSources = [],
    ) {}
}
