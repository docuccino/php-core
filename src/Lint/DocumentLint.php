<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Pipeline\Assembler;

/**
 * A document transformer that only reports what it finds: it reads the draft and writes nothing to it. So a
 * build may run it beside the rest of its work, over a copy of the draft taken at its turn ({@see Assembler}),
 * and gather what it reported at the end; anything it wrote would reach no document.
 *
 * @internal
 */
interface DocumentLint extends DocumentTransformer {}
