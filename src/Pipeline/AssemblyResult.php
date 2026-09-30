<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Closure;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Lint\DocumentLint;

/**
 * The assembled document array plus the diagnostics raised while merging fragments, hoisting components and
 * applying overlays and transformers — read through {@see diagnostics()} alone, since the lints' part of them
 * may still be being made beside the build ({@see DocumentLint}).
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
     * @param  list<Closure(): list<Diagnostic>>  $outstanding  what each run of lints reports
     */
    public function __construct(
        public array $document,
        private array $diagnostics = [],
        public array $schemaSources = [],
        private array $outstanding = [],
    ) {}

    /**
     * Everything the assembly reported, which waits for the lints: a build asks once it has nothing else to do.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        $diagnostics = $this->diagnostics;
        foreach ($this->outstanding as $pending) {
            foreach ($pending() as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }
        }

        return $diagnostics;
    }
}
