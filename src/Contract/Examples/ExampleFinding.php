<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Examples;

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\Violation;

/**
 * One documented example that does not satisfy the schema it sits beside — or the reference standing
 * where the audit went looking for examples, naming nothing.
 *
 * Both are the SAME answer on purpose: a `$ref` at a name the document does not define is a broken
 * document rather than an uncheckable one, which is what {@see ContractChecker} already says about the
 * identical situation. The two halves of the product would otherwise disagree about what a typo in a
 * pointer means, and the audit's half — skip whatever is behind it, say nothing — is how a suite comes
 * to report that every example it could find was fine.
 */
final readonly class ExampleFinding
{
    /**
     * @param  string  $pointer  where the example lives in the document, or where the reference stands
     * @param  string  $label  how a reader would name it: `GET /api/invoices → 200 application/json`
     * @param  list<Violation>  $violations
     * @param  string|null  $unresolvedRef  the reference that resolved to nothing — then no example was
     *                                      ever read here, and a renderer saying one failed its schema
     *                                      would be describing something that does not exist
     */
    public function __construct(
        public string $pointer,
        public string $label,
        public array $violations,
        public ?string $unresolvedRef = null,
    ) {}
}
