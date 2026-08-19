<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Examples;

/**
 * What the example audit found. `checked` matters as much as `findings`: "no example disagrees with its
 * schema" says nothing useful when the document carries no examples at all.
 */
final readonly class ExampleReport
{
    /**
     * @param  list<ExampleFinding>  $findings
     */
    public function __construct(
        public int $checked,
        public array $findings,
    ) {}

    public function ok(): bool
    {
        return $this->findings === [];
    }
}
